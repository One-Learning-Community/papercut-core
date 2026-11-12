<?php

declare(strict_types=1);

namespace Papercut\Layout;

use Papercut\{Image, Node, OverflowMode, PdfPage};
use Papercut\Backend\{Backend, ImageHandle, PdfPageHandle, PdfPageLoader};
use Papercut\Exception\UnsupportedFeature;
use Papercut\Export\{FidelityLevel, FidelityReport};
use Papercut\Page;
use Papercut\Style\{ComputedStyle, PageThemes, StyleResolver, Theme};
use Papercut\Text\{FontRegistry, Itemizer, SvgFonts};
use Papercut\Toc\TocIndex;
use Papercut\Tree\{NodePath, Region};

/**
 * Shared state for one layout run: fonts, theme, images, warnings, the
 * current node path and the overflow mode.
 *
 * @internal Part of Papercut's engine, not its public API. It may change in any release.
 */
final class LayoutContext
{
    /** @var list<string|array{Node, ?ComputedStyle, ?int}> the path's segments; a node's is named only when the path is read (NodePath::segment()) */
    private array $path = [];
    /** @var \SplObjectStorage<Theme, Itemizer> one per theme, which the itemizer reads span styles from */
    private \SplObjectStorage $itemizers;
    /** Open lists by kind, for depth-dependent markers. */
    private int $orderedLists = 0;
    private int $unorderedLists = 0;
    /** @var array<string, true> warnings already reported through warnOnce(), by path and message */
    private array $warned = [];
    /** @var array<string, PdfPageHandle> by source, page and password */
    private array $pdfPages = [];

    /** @var array<string, ImageHandle> */
    private array $images = [];
    /** @var array<string, SvgFonts> by family */
    private array $svgFonts = [];
    /** Path of the deepest node that returned DoesNotFit under fresh constraints; the paginator resets it. */
    public private(set) ?string $lastFailurePath = null;
    /** The region being laid out; the paginator sets it around each call. Body unless it says otherwise. */
    public Region $region = Region::Body;
    /** @var list<array{node: Node, style: ComputedStyle}> every node being laid out, outermost first, the current one last */
    private array $stack = [];
    /** Stack index of the region root while inRegion() runs, or null outside it. */
    private ?int $rootIndex = null;
    /** Off while laying out headers, footers and backgrounds, which repeat on every page. */
    public bool $bookmarks = true;
    /** Off while laying out repeating bands, like $bookmarks. */
    public bool $anchors = true;
    /** The headings the document's tables of contents list; empty when there is none. */
    public TocIndex $toc;
    /** Off for a trial layout that must not clip a too-tall node in place (Clip mode), so the paginator can try the next page instead. */
    public bool $clipInPlace = true;
    /**
     * On for a layout that only needs to know where blocks land, such as Word export's table of contents page numbers.
     * Text runs are placed without re-measuring merged runs for kerning across words, and highlights, underlines and
     * links are not drawn. Line breaks and heights are the same as a full layout's; text run x positions are approximate,
     * so nothing may size or break from them.
     */
    public bool $measureOnly = false;
    /** Strict export: content the PDF would drop, such as characters no font has, throws `UnsupportedFeature`. */
    public bool $strict = false;
    /** On inside quietly(). */
    public private(set) bool $quiet = false;
    private bool $heldBack = false;
    /** On inside trial(): warnings, fidelity notes and failure paths are discarded. */
    public private(set) bool $muted = false;
    public private(set) StyleResolver $styles;
    /** The theme of each page; when set, usePage() switches to the page's theme. */
    public ?PageThemes $themes = null;

    public function __construct(
        public readonly FontRegistry $fonts,
        public private(set) Theme $theme,
        public readonly Backend $backend,
        public readonly OverflowMode $overflow,
        private readonly ?\Closure $onWarning,
        /** @var list<string> */
        private readonly array $fallbackFamilies = [],
        /** The format named in fidelity reports from layout. */
        public readonly string $format = 'pdf',
    ) {
        $this->styles = new StyleResolver($theme);
        $this->itemizers = new \SplObjectStorage();
        $this->toc = TocIndex::empty();
    }

    /** The theme's root style, computed once per layout run so memoized child styles are shared across pages. */
    public function rootStyle(): ComputedStyle
    {
        return $this->styles->root();
    }

    /** Lays out with the page's theme from here on; the same Theme object as before keeps its resolver and itemizer. No-op without page themes. */
    public function usePage(Page $page): void
    {
        if ($this->themes === null) {
            return;
        }
        $this->theme = $this->themes->theme($page);
        $this->styles = $this->themes->styles($page);
    }

    public function itemizer(): Itemizer
    {
        // Strict mode checks text on the painted pages (PdfExporter), so a header laid out only to measure it never throws.
        // A weak reference, so the context and its itemizers form no cycle: the export's caches are freed when it returns.
        $self = \WeakReference::create($this);
        return $this->itemizers[$this->theme] ??= new Itemizer($this->fonts, static function (string $msg) use ($self): void {
            // The context outlives its itemizers; if that ever changes, fail loudly rather than drop the warning.
            ($self->get() ?? throw new \LogicException('A warning arrived after its layout context was freed.'))->warn($msg);
        }, $this->fallbackFamilies, $this->theme, $this->styles);
    }

    /** SVG text with characters no font has prints empty boxes: content the PDF drops, so strict mode throws. */
    private function missingGlyphs(string $msg): void
    {
        if ($this->strict) {
            throw UnsupportedFeature::from(new FidelityReport(
                FidelityLevel::Dropped,
                $this->format,
                lcfirst($msg) . ' (register a fallback font that has them)',
                $this->path(),
            ));
        }
    }

    /**
     * Runs $fn for a layout that may never be rendered (the paginator's look-ahead at later headers
     * and footers): a table too wide for its box neither throws nor reports. The second value is
     * false when that held something back, so the caller lays it out again if it is rendered.
     *
     * @template T
     * @param \Closure(): T $fn
     * @return array{T, bool}
     */
    public function quietly(\Closure $fn): array
    {
        [$quiet, $held] = [$this->quiet, $this->heldBack];
        $this->quiet = true;
        $this->heldBack = false;
        try {
            $result = $fn();
            return [$result, !$this->heldBack];
        } finally {
            $this->quiet = $quiet;
            $this->heldBack = $held;
        }
    }

    /**
     * Runs $fn for a layout whose result may be thrown away (a column flow trying heights): nothing it
     * reports is kept, whether a warning, a fidelity note or the failure path, and a too-tall node is not
     * clipped in place.
     *
     * @template T
     * @param \Closure(): T $fn
     * @return T
     */
    public function trial(\Closure $fn): mixed
    {
        [$muted, $clip] = [$this->muted, $this->clipInPlace];
        $this->muted = true;
        $this->clipInPlace = false;
        try {
            return $this->quietly($fn)[0];
        } finally {
            $this->muted = $muted;
            $this->clipInPlace = $clip;
        }
    }

    /** Inside quietly(): notes that a report or throw was held back. */
    public function holdBack(): void
    {
        $this->heldBack = true;
    }

    /** Reports a fidelity approximation at the current path, once per path and feature. */
    public function approximated(string $feature): void
    {
        $report = new FidelityReport(FidelityLevel::Approximated, $this->format, $feature, $this->path());
        $key = "\0approximated\0" . $report->path . "\0" . $feature;
        if ($this->muted || $this->onWarning === null || isset($this->warned[$key])) {
            return;
        }
        $this->warned[$key] = true;
        $r = new \ReflectionFunction($this->onWarning);
        $r->getNumberOfParameters() === 1 && !$r->isVariadic()
            ? ($this->onWarning)($report->message())
            : ($this->onWarning)($report->message(), $report);
    }

    /** $withPath false: the message already names its node. */
    public function warn(string $msg, bool $withPath = true): void
    {
        if ($this->muted || $this->onWarning === null) {
            return;
        }
        $path = $withPath ? $this->path() : '';
        ($this->onWarning)($path === '' ? $msg : sprintf('[%s] %s', $path, $msg));
    }

    /** Like warn(), but a message already reported for the same path is not repeated, as when a trial layout is run again. */
    public function warnOnce(string $msg, bool $withPath = true): void
    {
        $key = ($withPath ? $this->path() : '') . "\0" . $msg;
        if ($this->muted || isset($this->warned[$key])) {
            return;
        }
        $this->warned[$key] = true;
        $this->warn($msg, $withPath);
    }

    /** $name may carry a sibling index, e.g. "view[2]". */
    public function pushPath(string $name): void
    {
        $this->path[] = $name;
    }

    /** Pushes a node's segment of the path, named only if the path is read (NodePath::segment()). */
    public function pushNodePath(Node $node, ?ComputedStyle $style, ?int $index): void
    {
        $this->path[] = [$node, $style, $index];
    }

    public function popPath(): void
    {
        array_pop($this->path);
    }

    /** @internal LayoutEngine only: the node being laid out, after its style is resolved. */
    public function pushNode(Node $node, ComputedStyle $style): void
    {
        $this->stack[] = ['node' => $node, 'style' => $style];
    }

    /** @internal */
    public function popNode(): void
    {
        array_pop($this->stack);
    }

    /**
     * Runs $fn with the region set; the first node laid out inside is the region's root (the body View,
     * a header or footer column, the background), which ancestors() and lineage() leave out.
     *
     * @template T
     * @param \Closure(): T $fn
     * @return T
     */
    public function inRegion(Region $region, \Closure $fn): mixed
    {
        $was = [$this->region, $this->rootIndex];
        $this->region = $region;
        $this->rootIndex = count($this->stack);
        try {
            return $fn();
        } finally {
            [$this->region, $this->rootIndex] = $was;
        }
    }

    /**
     * The nodes around the one being laid out, from just below the region root to its parent: what a
     * layouter needs to judge itself. Outside inRegion() (a direct engine call) nothing is dropped.
     *
     * @return list<array{node: Node, style: ComputedStyle}>
     */
    public function ancestors(): array
    {
        return array_slice($this->lineage(), 0, -1);
    }

    /**
     * Like ancestors() but ending with the node being laid out: what a layouter needs to judge its own
     * children, which are not pushed until they are laid out.
     *
     * @return list<array{node: Node, style: ComputedStyle}>
     */
    public function lineage(): array
    {
        return array_slice($this->stack, $this->rootIndex === null ? 0 : $this->rootIndex + 1);
    }

    public function path(): string
    {
        $names = [];
        foreach ($this->path as $i => $segment) {
            // Named once: a table of contents reads the path at every node.
            $names[] = $this->path[$i] = is_string($segment) ? $segment : NodePath::segment(...$segment);
        }
        return implode(' > ', $names);
    }

    /** Opens a list; returns its 1-based depth among open lists of the same kind. */
    public function enterList(bool $ordered): int
    {
        return $ordered ? ++$this->orderedLists : ++$this->unorderedLists;
    }

    public function leaveList(bool $ordered): void
    {
        if ($ordered) {
            $this->orderedLists = max(0, $this->orderedLists - 1);
        } else {
            $this->unorderedLists = max(0, $this->unorderedLists - 1);
        }
    }

    public function setLastFailurePath(?string $path): void
    {
        if ($this->muted) {
            return;
        }
        $this->lastFailurePath = $path;
    }

    /**
     * @throws \Papercut\Exception\PdfUnreadable
     * @throws \LogicException when the backend cannot draw pages of other PDFs
     */
    public function pdfPage(PdfPage $node): PdfPageHandle
    {
        $backend = $this->backend;
        if (!$backend instanceof PdfPageLoader) {
            throw new \LogicException(sprintf('%s cannot draw pages of other PDFs; use the default PhpPdfBackend.', $backend::class));
        }
        $source = $node->source();
        $key = $source['kind'] . ':' . sha1($source['value']) . ':' . $node->pageNumber() . ':' . sha1($node->password());

        return $this->pdfPages[$key] ??= $node->load($backend);
    }

    public function image(Image $node): ImageHandle
    {
        $source = $node->source();
        $value = $source['value'];
        if ($source['kind'] === 'file') {
            return $this->images['file:' . $value] ??= $this->backend->loadImageFile($value);
        }
        return $this->images['bytes:' . sha1($value)] ??= $this->backend->loadImageBytes($value);
    }

    /** Fonts for SVG text drawn in an element of this style; warnings name the element's path. */
    public function svgFonts(ComputedStyle $style): SvgFonts
    {
        $self = \WeakReference::create($this); // no cycle, as in itemizer()
        return $this->svgFonts[strtolower($style->fontFamily)] ??= new SvgFonts($this->fonts, $style->fontFamily, $this->fallbackFamilies, static function (string $msg) use ($self): void {
            $ctx = $self->get() ?? throw new \LogicException('A warning arrived after its layout context was freed.');
            $ctx->missingGlyphs($msg);
            $ctx->warn($msg, withPath: false);
        });
    }

    public function resolveStyle(Node $node, ComputedStyle $parent): ComputedStyle
    {
        return $this->styles->resolve($node, $parent);
    }
}
