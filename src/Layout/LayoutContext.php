<?php

declare(strict_types=1);

namespace Papercut\Layout;

use Papercut\{Image, Node, OverflowMode, PdfPage};
use Papercut\Backend\{Backend, ImageHandle, PdfPageHandle, PdfPageLoader};
use Papercut\Export\{FidelityLevel, FidelityReport};
use Papercut\Style\{ComputedStyle, StyleResolver, Theme};
use Papercut\Text\{FontRegistry, Itemizer, SvgFonts};

/**
 * Shared state for one layout run: fonts, theme, images, warnings, the
 * current node path and the overflow mode.
 */
final class LayoutContext
{
    /** @var list<string> */
    private array $path = [];
    private ?Itemizer $itemizer = null;
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
    /** Off while laying out headers, footers and backgrounds, which repeat on every page. */
    public bool $bookmarks = true;
    /** Off for a trial layout that must not clip a too-tall node in place (Clip mode), so the paginator can try the next page instead. */
    public bool $clipInPlace = true;
    /** On inside quietly(). */
    public private(set) bool $quiet = false;
    private bool $heldBack = false;
    public readonly StyleResolver $styles;

    public function __construct(
        public readonly FontRegistry $fonts,
        public readonly Theme $theme,
        public readonly Backend $backend,
        public readonly OverflowMode $overflow,
        private readonly ?\Closure $onWarning,
        /** @var list<string> */
        private readonly array $fallbackFamilies = [],
        /** The format named in fidelity reports from layout. */
        public readonly string $format = 'pdf',
    ) {
        $this->styles = new StyleResolver($theme);
    }

    /** The theme's root style, computed once per layout run so memoized child styles are shared across pages. */
    public function rootStyle(): ComputedStyle
    {
        return $this->styles->root();
    }

    public function itemizer(): Itemizer
    {
        return $this->itemizer ??= new Itemizer($this->fonts, function (string $msg): void {
            $this->warn($msg);
        }, $this->fallbackFamilies, $this->theme);
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
        if ($this->onWarning === null || isset($this->warned[$key])) {
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
        if ($this->onWarning === null) {
            return;
        }
        $path = $withPath ? $this->path() : '';
        ($this->onWarning)($path === '' ? $msg : sprintf('[%s] %s', $path, $msg));
    }

    /** Like warn(), but a message already reported for the same path is not repeated, as when a trial layout is run again. */
    public function warnOnce(string $msg, bool $withPath = true): void
    {
        $key = ($withPath ? $this->path() : '') . "\0" . $msg;
        if (isset($this->warned[$key])) {
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

    public function popPath(): void
    {
        array_pop($this->path);
    }

    public function path(): string
    {
        return implode(' > ', $this->path);
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
        return $this->svgFonts[strtolower($style->fontFamily)] ??= new SvgFonts($this->fonts, $style->fontFamily, $this->fallbackFamilies, function (string $msg): void {
            $this->warn($msg, withPath: false);
        });
    }

    public function resolveStyle(Node $node, ComputedStyle $parent): ComputedStyle
    {
        return $this->styles->resolve($node, $parent);
    }
}
