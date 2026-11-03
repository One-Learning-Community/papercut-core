<?php

declare(strict_types=1);

namespace Papercut\Html;

use Papercut\Backend\Backend;
use Papercut\Export\LoadedImage;
use Papercut\{ColumnBreak, Document, Image, Link, ListItem, ListNode, Node, Page, PageBreak, PageNumber, PdfPage, Place, QrCode, Spacer, Svg, Table, TableCell, TableRow, Text, Toc, View};
use Papercut\Barcode\Bacon\BaconQrSvg;
use Papercut\Barcode\ModuleRects;
use Papercut\Exception\InvalidValue;
use Papercut\Export\{Fidelity, HtmlOptions};
use Papercut\Layout\{GridCell, TableGrid, WidthResolver};
use Papercut\Style\{ComputedStyle, Justify, ListMarker, StyleName};
use Papercut\Text\FontStyle;
use Papercut\Value\{Auto, Fr, Length, Percent};
use Papercut\Toc\TocIndex;
use Papercut\Layout\ColumnFlowLayouter;
use Papercut\Tree\{ColumnFlows, Region, TreeVisitor, Visit};

/**
 * Turns a tree walk into HTML. One method per node type, so new node types slot in without touching the walk.
 *
 * @internal Part of Papercut's engine, not its public API. It may change in any release.
 */
final class HtmlWriter implements TreeVisitor
{
    private string $out = '';
    private readonly int $pageCount;
    /** The theme of the page being written. */
    private \Papercut\Style\Theme $theme;
    private readonly \Papercut\Style\PageThemes $themes;
    private ?Page $page = null;
    /** Output held back while a hidden region is walked; null outside one. */
    private ?string $held = null;

    /** @var list<ComputedStyle> */
    private array $styles = [];
    /** @var list<string> closing markup, one per open node */
    private array $closers = [];
    /** @var list<?array{gap: float, count: int, prev: float}> one per open node: the spacing state of a column flow, null for anything else */
    private array $flowFrames = [];
    /** Set by flowColumns() when the view it just wrote is a flow that got columns. */
    private bool $openedFlow = false;
    /** @var list<?string> the open thead/tbody of each open table */
    private array $sections = [];

    /** @var \SplObjectStorage<TableRow, list<GridCell>>|null grid placements of the rows being written */
    private ?\SplObjectStorage $rowPlacements = null;
    /** @var \WeakMap<TableCell, true> cells laid out as plain columns because no row holds them */
    private \WeakMap $strays;
    /** @var array<string,true> */
    private array $families = [];
    /** @var array<string, array{family: string, variants: list<FontVariant>, path: ?string}> by lowercased family */
    private array $usage = [];
    /** @var list<float> horizontal padding, border and margin of each open node */
    private array $edges = [];
    /** Content width of the current region's page, in points. */
    private float $regionWidth = 0.0;
    /** Open block links and span-link-bearing paragraphs inside them; an inner link cannot be an anchor. */
    private int $linkDepth = 0;
    /** @var array{ordered: int, unordered: int} open lists of each kind, which sets the default marker */
    private array $listDepth = ['ordered' => 0, 'unordered' => 0];
    /** @var list<bool> whether each open list is ordered */
    private array $openLists = [];
    /** @var array<string, LoadedImage> images already loaded this export, by source */
    private array $images = [];
    /** @var array<string, string> element id of each table-of-contents heading, by node path */
    private array $slugs = [];
    private readonly TocIndex $toc;

    public function __construct(
        Document $doc,
        private readonly Fidelity $fidelity,
        private readonly HtmlOptions $options,
        private readonly Backend $backend,
        ?TocIndex $toc = null,
        ?\Papercut\Style\PageThemes $themes = null,
    ) {
        $this->themes = $themes ?? \Papercut\Style\PageThemes::for($options->theme(), $doc);
        $this->toc = $toc ?? TocIndex::empty();
        $taken = [];
        foreach ($this->toc->entries(1, 4) as $entry) {
            $this->slugs[$entry->key] = self::slug($entry->text, $taken);
        }
        $this->strays = new \WeakMap();
        $this->pageCount = count($doc->pages());
        $this->theme = $this->themes->document();
    }

    public static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /**
     * Per lowercased family: the first spelling seen, the distinct weight/style pairs in first-seen order,
     * and the first node path that used it (null when only the root style did).
     *
     * @return array<string, array{family: string, variants: list<FontVariant>, path: ?string}>
     */
    public function fontUsage(): array
    {
        return $this->usage;
    }

    private function recordFont(ComputedStyle $s, ?string $path): void
    {
        $this->families[$s->fontFamily] = true;
        $key = strtolower($s->fontFamily);
        $variant = new FontVariant($s->fontWeight, $s->italic ? FontStyle::Italic : FontStyle::Normal);
        if (!isset($this->usage[$key])) {
            $this->usage[$key] = ['family' => $s->fontFamily, 'variants' => [], 'path' => $path];
        }
        foreach ($this->usage[$key]['variants'] as $seen) {
            if ($seen->weight === $variant->weight && $seen->style === $variant->style) {
                return;
            }
        }
        $this->usage[$key]['variants'][] = $variant;
    }

    /** @return list<string> font families of every style written, in first-seen order */
    public function usedFamilies(): array
    {
        return array_keys($this->families);
    }

    public function html(): string
    {
        return $this->out;
    }

    public function enterPage(Page $page, int $index): void
    {
        $this->page = $page;
        $this->theme = $this->themes->theme($page);
        $path = $this->pageCount > 1 ? sprintf('page[%d]', $index) : 'page';
        $page->checkMargins($path);
        $this->fidelity->approximated('page box', $path);
        if ($page->headerNodes() !== [] || $page->footerNodes() !== []) {
            $this->fidelity->approximated('header and footer once per page', $path);
        }
        if ($page->evenHeaderNodes() !== null || $page->evenFooterNodes() !== null) {
            $this->fidelity->approximated('even-page header and footer', $path);
        }
        if ($this->firstFooterReplacesFooter($page)) {
            $this->fidelity->approximated('first-page footer', $path);
        }
        if ($page->templatePage() !== null) {
            $this->fidelity->dropped('PDF page template', $path);
        }
        if ($page->backgroundNodes() !== []) {
            $this->fidelity->approximated('background layer', $path);
        }
        if ($page->overlayPlaces() !== []) {
            $this->fidelity->approximated('overlay positions', $path);
        }
        $m = $page->margins();
        $this->out .= '<section class="pc-page" style="' . self::attr([
            'max-width' => Css::pt($page->width()),
            'min-height' => Css::pt($page->height()),
            'padding' => Css::pt($m->top->points) . ' ' . Css::pt($m->right->points) . ' ' . Css::pt($m->bottom->points) . ' ' . Css::pt($m->left->points),
            ...($page->overlayPlaces() === [] ? [] : ['position' => 'relative']),
            ...$this->pageTextCss($page),
        ]) . '">';
    }

    /**
     * The text declarations a page's root style adds to the document root, which the .papercut div carries;
     * everything inside the page is written relative to the page root, so the cascade stays right.
     *
     * @return array<string,string>
     */
    private function pageTextCss(Page $page): array
    {
        $root = $this->themes->styles($page)->root();
        $document = $this->themes->documentStyles()->root();
        if ($root === $document) {
            return [];
        }
        $fonts = $this->options->fonts();

        return Css::text($root, $document, $fonts->fallbacks, $fonts);
    }

    public function leavePage(Page $page, int $index): void
    {
        $this->out .= '</section>';
    }

    public function enterRegion(Region $region, ComputedStyle $root): void
    {
        $this->styles = [$root];
        $this->edges = [];
        $m = $this->page?->margins();
        $this->regionWidth = $this->page === null || $m === null ? 0.0 : max(0.0, $this->page->width() - $m->left->points - $m->right->points);
        $style = match (true) {
            $region->isHeader() => ' style="margin-bottom:' . Css::pt($this->page?->headerGapLength()->points ?? 0.0) . '"',
            $region->isFooter() => ' style="margin-top:' . Css::pt($this->page?->footerGapLength()->points ?? 0.0) . '"',
            $region === Region::Overlay => ' style="position:absolute;inset:0"',
            default => '',
        };
        if (!$this->shown($region)) {
            $this->held = $this->out;
        } else {
            $this->recordFont($root, null);
        }
        $class = $region->isHeader() ? 'header' : ($region->isFooter() ? 'footer' : $region->value);
        $this->out .= '<div class="pc-' . $class . '"' . $style . '>';
    }

    /**
     * One HTML run shows its top: the first-page header if set, else the normal one; and its bottom: the
     * normal footer, or the first-page footer when the page has no normal one.
     */
    private function shown(Region $region): bool
    {
        $first = $this->page?->firstHeaderNodes();
        $firstFooter = $this->page?->firstFooterNodes() ?? [];
        $onlyFirstFooter = $firstFooter !== [] && $this->page->footerNodes() === [];

        return match ($region) {
            Region::Header => $first === null,
            Region::FirstHeader => $first !== null && $first !== [],
            Region::Footer => !$onlyFirstFooter,
            Region::FirstFooter => $onlyFirstFooter,
            Region::EvenHeader, Region::EvenFooter => false,
            default => true,
        };
    }

    /** A first-page footer the single HTML run cannot show: the page's normal footer (or none) is shown instead. */
    private function firstFooterReplacesFooter(Page $page): bool
    {
        $first = $page->firstFooterNodes();

        return $first !== null && $page->footerNodes() !== [];
    }

    public function leaveRegion(Region $region): void
    {
        if ($this->held !== null) {
            $this->out = $this->held;
            $this->held = null;
        } else {
            $this->out .= '</div>';
        }
        $this->styles = [];
    }

    public function enter(Node $node, ComputedStyle $style, Visit $at): bool
    {
        // A hidden region is not walked: it loads no images, records no fonts and raises no notes.
        if ($this->held !== null) {
            return false;
        }
        $parent = end($this->styles);
        \assert($parent instanceof ComputedStyle);
        $inFlow = $this->parentIsColumnFlow($at);
        $start = strlen($this->out);
        $closer = match (true) {
            $node instanceof View => $this->view($node, $style, $parent, $at),
            $node instanceof Text => $this->text($node, $style, $parent, $at),
            $node instanceof Link => $this->link($node, $style, $parent, $at),
            $node instanceof Spacer => $this->spacer($node, $style, $inFlow, $at),
            $node instanceof PageBreak => $this->pageBreak(),
            $node instanceof ColumnBreak => $this->columnBreak($at),
            $node instanceof PageNumber => '',
            $node instanceof Image => $this->image($node, $style, $at),
            $node instanceof QrCode => $this->qrCode($node, $style, $at),
            $node instanceof Svg => $this->svg($node, $style, $at),
            $node instanceof PdfPage => $this->pdfPage($at),
            $node instanceof Place => $this->place($node),
            $node instanceof Table => $this->table($node, $style, $at),
            $node instanceof TableRow => $this->tableRow($node, $style, $at),
            $node instanceof TableCell => $this->tableCell($node, $style, $at),
            $node instanceof ListNode => $this->list($node, $style, $parent, $at),
            $node instanceof ListItem => $this->listItem($node, $style, $parent, $at),
            $node instanceof Toc => $this->tocNav($node, $style, $parent, $at),
            default => throw new \LogicException('HTML export of ' . $node->debugName() . ' is not supported'),
        };
        if ($inFlow) {
            $this->spaceInFlow($style, $start);
        }
        $this->flowFrames[] = $node instanceof View && $this->openedFlow ? ['gap' => $style->gap, 'count' => 0, 'prev' => 0.0] : null;
        $this->openedFlow = false;
        $this->styles[] = $style;
        $this->edges[] = Css::horizontalEdges($style);
        $this->closers[] = $closer;

        return true;
    }

    public function leave(Node $node, ComputedStyle $style, Visit $at): void
    {
        if ($this->held !== null) {
            return;
        }
        array_pop($this->styles);
        array_pop($this->edges);
        array_pop($this->flowFrames);
        if ($node instanceof Link) {
            $this->linkDepth--;
        }
        if ($node instanceof ListNode) {
            $this->listDepth[array_pop($this->openLists) ? 'ordered' : 'unordered']--;
        }
        if ($node instanceof Table) {
            $open = array_pop($this->sections);
            $this->out .= $open === null ? '' : '</' . $open . '>';
        }
        $this->out .= (string) array_pop($this->closers);
    }

    /** @return array<string,string> */
    private function textCss(ComputedStyle $s, ?ComputedStyle $parent, string $path): array
    {
        $this->recordFont($s, $path);

        return Css::text($s, $parent, $this->options->fonts()->fallbacks, $this->options->fonts());
    }

    private function view(View $n, ComputedStyle $s, ComputedStyle $parent, Visit $at): string
    {
        $this->checkDecoration($s, $parent, $at->path());
        $tag = $n->htmlElement();
        $columns = $this->flowColumns($n, $at);
        $decls = [...($tag === 'div' ? [] : ['margin' => '0']), ...Css::box($s), ...($columns ?? Css::flexContainer($s))];
        if ($this->parentIsFlex($at)) {
            $decls += Css::flexItem($s);
        }
        $decls = [...$decls, ...Css::pagination($s, $parent), ...$this->textCss($s, $parent, $at->path())];
        $this->out .= '<' . $tag . self::styleAttr($decls) . '>';

        return '</' . $tag . '>';
    }

    /**
     * Column CSS for a flow; null for any other view, or a markup flow that can't have columns here.
     *
     * @return ?array<string,string>
     */
    private function flowColumns(View $n, Visit $at): ?array
    {
        $spec = $n->columnSpec();
        if ($spec === null) {
            return null;
        }
        $placement = ColumnFlows::placement($at->ancestors(), $at->region());
        if ($placement->isBlocked()) {
            if (!$spec->fromMarkup) {
                throw ColumnFlows::misplaced($placement, $at->path());
            }
            $this->fidelity->approximated('columns ' . ColumnFlowLayouter::where($placement), $at->path());
            return null;
        }
        if ($spec->shares !== null && count(array_unique($spec->shares)) > 1) {
            $this->fidelity->approximated('unequal column widths', $at->path());
        }
        if (count(array_unique($spec->gaps)) > 1) {
            $this->fidelity->approximated('unequal column gaps', $at->path());
        }

        $this->openedFlow = true;

        return Css::columns($spec);
    }

    /**
     * A flow's children are block children in CSS, so the spacing PDF layout puts between them is
     * written onto each child: the flow's gap plus the previous child's bottom margin go into its
     * margin-top. Adding the previous bottom margin stops sibling margins collapsing (they would
     * otherwise give max(bottom, top), not bottom + top) without padding or wrappers that would
     * break column fragmentation: the collapsed result is exactly gap + bottom + top.
     */
    private function spaceInFlow(ComputedStyle $style, int $start): void
    {
        $frame = end($this->flowFrames);
        if (!is_array($frame)) {
            return;
        }
        $extra = $frame['count'] === 0 ? 0.0 : $frame['gap'] + $frame['prev'];
        array_pop($this->flowFrames);
        $this->flowFrames[] = ['gap' => $frame['gap'], 'count' => $frame['count'] + 1, 'prev' => $style->margin->bottom->points];
        $tagEnd = strpos($this->out, '>', $start);
        if ($extra === 0.0 || $tagEnd === false || ($this->out[$start] ?? '') !== '<') {
            return;
        }
        $decl = 'margin-top:' . Css::pt($style->margin->top->points + $extra);
        $tag = substr($this->out, $start, $tagEnd - $start);
        $at = strpos($tag, ' style="');
        if ($at === false) {
            $tag .= ' style="' . $decl . '"';
        } else {
            $close = strpos($tag, '"', $at + 8);
            $close = $close === false ? strlen($tag) : $close;
            $tag = substr($tag, 0, $close) . ';' . $decl . substr($tag, $close);
        }
        $this->out = substr($this->out, 0, $start) . $tag . substr($this->out, $tagEnd);
    }

    private function columnBreak(Visit $at): string
    {
        if (!ColumnFlows::columnBreakAllowed($at->ancestors())) {
            throw ColumnFlows::strayBreak($at->path());
        }
        $this->out .= '<div class="pc-column-break"></div>';

        return '';
    }

    private function list(ListNode $n, ComputedStyle $s, ComputedStyle $parent, Visit $at): string
    {
        $this->checkDecoration($s, $parent, $at->path());
        $kind = $n->isOrdered() ? 'ordered' : 'unordered';
        $this->openLists[] = $n->isOrdered();
        $depth = ++$this->listDepth[$kind];
        $marker = $n->markerStyle() ?? ListMarker::forDepth($n->isOrdered(), $depth);
        $decls = [
            'list-style-type' => $marker->value,
            'margin' => '0',
            'padding' => '0 0 0 2em',
            ...Css::box($s),
            ...($this->parentIsFlex($at) ? Css::flexItem($s) : []),
            ...Css::pagination($s, $parent),
            ...$this->textCss($s, $parent, $at->path()),
        ];
        $tag = $n->isOrdered() ? 'ol' : 'ul';
        $start = $n->isOrdered() && $n->startNumber() !== 1 ? ' start="' . $n->startNumber() . '"' : '';
        $this->out .= '<' . $tag . $start . self::styleAttr($decls) . '>';

        return '</' . $tag . '>';
    }

    private function listItem(ListItem $n, ComputedStyle $s, ComputedStyle $parent, Visit $at): string
    {
        $this->checkDecoration($s, $parent, $at->path());
        $decls = [...Css::box($s), ...Css::pagination($s, $parent), ...$this->textCss($s, $parent, $at->path())];
        $value = $n->valueOverride() !== null ? ' value="' . $n->valueOverride() . '"' : '';
        $this->out .= '<li' . $value . self::styleAttr($decls) . '>';

        return '</li>';
    }

    /**
     * A lowercase id from the heading text: letters and digits of any script, other runs as one hyphen. Unique among $taken.
     *
     * @param array<string, true> $taken
     */
    private static function slug(string $text, array &$taken): string
    {
        $base = trim((string) preg_replace('/[^\p{L}\p{M}\p{N}]+/u', '-', mb_strtolower($text)), '-');
        $base = $base === '' ? 'section' : $base;
        $slug = $base;
        for ($n = 2; isset($taken[$slug]); $n++) {
            $slug = $base . '-' . $n;
        }
        $taken[$slug] = true;

        return $slug;
    }

    /** The contents list is a nav of nested ordered lists of links; the page numbers and leaders are left out. */
    private function tocNav(Toc $n, ComputedStyle $s, ComputedStyle $parent, Visit $at): string
    {
        $this->checkDecoration($s, $parent, $at->path());
        $this->fidelity->approximated('table of contents page numbers', $at->path());
        $this->out .= '<nav class="papercut-toc"' . self::styleAttr([...Css::box($s), ...($this->parentIsFlex($at) ? Css::flexItem($s) : []), ...Css::pagination($s, $parent), ...$this->textCss($s, $parent, $at->path())]) . '>';
        if ($n->titleText() !== null) {
            $title = $s->child($this->theme->named('toc.title'));
            $this->out .= '<p class="papercut-toc-title"' . self::styleAttr(['margin' => '0', ...Css::box($title), ...$this->textCss($title, $s, $at->path())]) . '>' . self::esc($n->titleText()) . '</p>';
        }
        $entries = $this->toc->entries($n->fromLevel(), $n->toLevel());
        $listStyle = self::styleAttr(['list-style-type' => 'none', 'margin' => '0', 'padding' => '0']);
        /** @var list<int> the level of each open list's items; a skipped level still opens one list per step down */
        $open = [];
        foreach ($entries as $entry) {
            $level = $entry->level;
            if ($open === []) {
                $this->out .= '<ol' . $listStyle . '>';
                $open[] = $level;
            } elseif ($level > end($open)) {
                $this->out .= '<ol' . $listStyle . '>';
                $open[] = $level;
            } else {
                $this->out .= '</li>';
                // Close deeper lists. An entry between two open levels (a skipped level) joins the deeper list as its sibling.
                while (count($open) > 1 && $level < end($open)) {
                    if ($level > $open[count($open) - 2]) {
                        $open[array_key_last($open)] = $level;
                        break;
                    }
                    array_pop($open);
                    $this->out .= '</ol></li>';
                }
            }
            $style = $s->child($this->theme->named('toc.entry toc.level-' . max(1, min(4, $level))));
            $decls = [...Css::box($style), 'display' => 'block', 'color' => 'inherit', 'text-decoration' => 'none', ...$this->textCss($style, $s, $at->path())];
            $this->out .= '<li><a href="#' . self::esc($this->slugs[$entry->key]) . '"' . self::styleAttr($decls) . '>' . self::esc($entry->text) . '</a>';
        }
        if ($entries !== []) {
            $this->out .= '</li>' . str_repeat('</ol></li>', count($open) - 1) . '</ol>';
        }

        return '</nav>';
    }

    private function text(Text $t, ComputedStyle $s, ComputedStyle $parent, Visit $at): string
    {
        $override = self::overridesDecoration($s, $parent);
        // `code-block.code` is the code text of Decorator\CodeBlock; it exports as a pre like markup's own.
        $pre = StyleName::leads($t->styleName(), 'pre') || StyleName::leads($t->styleName(), 'code-block.code');
        $tag = match (true) {
            $pre => 'pre',
            $t->headingLevel() !== null => 'h' . $t->headingLevel(),
            default => 'p',
        };
        $decls = [...Css::box($s), ...$this->textCss($s, $parent, $at->path()), ...($this->parentIsFlex($at) ? Css::flexItem($s) : []), ...Css::pagination($s, $parent)];
        if ($pre) {
            // The browser's own pre rules (margin, font, tab width, white-space: pre) are replaced by the style's; long lines wrap like the PDF's.
            $decls = ['margin' => '0', 'overflow-wrap' => 'anywhere', 'tab-size' => '4', ...$this->fontCss($s), ...$decls, 'white-space' => $s->whiteSpace->value];
        }
        $id = isset($this->slugs[$at->path()]) ? ' id="' . self::esc($this->slugs[$at->path()]) . '"' : '';
        $this->out .= '<' . $tag . $id . self::styleAttr($decls) . '>';
        $inner = '';
        if ($pre && $t->isHtmlCode()) {
            $language = preg_replace('/[^A-Za-z0-9_+#.-]/', '', (string) $t->htmlCodeLanguage());
            $this->out .= '<code' . ($language !== '' && $language !== null ? ' class="language-' . $language . '"' : '') . '>';
            $inner = '</code>';
        }
        $nested = false;
        foreach ($t->spanList() as $span) {
            $override = $this->span($span, $s, $nested, $at->path()) || $override;
        }
        if ($nested) {
            $this->fidelity->approximated('nested link', $at->path());
        }
        if ($override) {
            $this->fidelity->approximated('text decoration override', $at->path());
        }

        return $inner . '</' . $tag . '>';
    }

    /** @return bool whether the span turns off a decoration the paragraph has on */
    private function span(\Papercut\Span $span, ComputedStyle $text, bool &$nested, string $path): bool
    {
        if ($span->lineBreak) {
            $this->out .= '<br>';
            return false;
        }
        $own = $this->theme->spanStyle($span);
        $style = $text->child($own);
        $diff = $this->textCss($style, $text, $path);
        if ($own->background !== null && !$own->background->isTransparent()) {
            $diff['background-color'] = Css::color($own->background);
        }
        $body = self::esc($span->text);
        $code = StyleName::leads($span->styleName, 'code');
        if ($code) {
            $diff = [...$this->fontCss($style), ...$diff];
        }
        $wrapped = $diff !== [] || $code;
        if ($wrapped) {
            $el = $code ? 'code' : 'span';
            $body = '<' . $el . self::styleAttr($diff) . '>' . $body . '</' . $el . '>';
        }
        if ($span->href !== null) {
            $href = self::href($span->href);
            if ($this->linkDepth > 0) {
                // An anchor inside an anchor is restructured by HTML parsers: keep the text and styles, lose the inner link.
                $nested = true;
                $body = $wrapped ? $body : '<span>' . $body . '</span>';
            } else {
                $body = '<a href="' . self::esc($href) . '">' . $body . '</a>';
            }
        }
        $this->out .= $body;

        return self::overridesDecoration($style, $text);
    }

    /**
     * The face and size, whatever the parent has: for elements whose browser default would otherwise show through.
     *
     * @return array<string,string>
     */
    private function fontCss(ComputedStyle $s): array
    {
        return array_intersect_key(Css::text($s, null, $this->options->fonts()->fallbacks, $this->options->fonts()), ['font-family' => 1, 'font-size' => 1]);
    }

    private function link(Link $l, ComputedStyle $s, ComputedStyle $parent, Visit $at): string
    {
        $href = self::href($l->uri());
        $this->checkDecoration($s, $parent, $at->path());
        $decls = [...Css::box($s), ...Css::flexContainer($s), 'flex-direction' => 'column', ...($this->parentIsFlex($at) ? Css::flexItem($s) : []), ...Css::pagination($s, $parent), ...$this->textCss($s, $parent, $at->path())];
        if ($this->linkDepth > 0) {
            $this->fidelity->approximated('nested link', $at->path());
            $this->linkDepth++;
            $this->out .= '<span class="pc-link"' . self::styleAttr($decls) . '>';

            return '</span>';
        }
        $this->linkDepth++;
        $this->out .= '<a class="pc-link" href="' . self::esc($href) . '"' . self::styleAttr($decls) . '>';

        return '</a>';
    }

    private function spacer(Spacer $sp, ComputedStyle $s, bool $inFlow, Visit $at): string
    {
        $fixed = $sp->fixedSize();
        if ($inFlow) {
            // A block child in a column flow: a fixed spacer is a height, a growing one has nothing to grow into.
            if ($fixed === null) {
                $this->fidelity->approximated('spacer growth in columns', $at->path());
            }
            $this->out .= '<div' . self::styleAttr($fixed === null ? [] : ['height' => Css::pt($fixed->points)]) . '></div>';

            return '';
        }
        $flex = $fixed !== null ? '0 0 ' . Css::pt($fixed->points) : Css::num($s->grow) . ' 0 0';
        $this->out .= '<div' . self::styleAttr(['flex' => $flex]) . '></div>';

        return '';
    }

    private function pageBreak(): string
    {
        $this->out .= '<div class="pc-break"></div>';

        return '';
    }

    private function loadImage(Image $n): LoadedImage
    {
        ['kind' => $kind, 'value' => $value] = $n->source();
        $key = $kind === 'file' ? 'file:' . $value : 'bytes:' . sha1($value);

        return $this->images[$key] ??= LoadedImage::load($n, $this->backend);
    }

    /** An inline SVG in module units, so it scales with the box and stays crisp. */
    private function qrCode(QrCode $n, ComputedStyle $s, Visit $at): string
    {
        $decls = Css::box($s);
        if ($this->parentIsFlex($at)) {
            $decls += Css::flexItem($s);
        }
        if (!isset($decls['width']) && !isset($decls['height'])) {
            $decls['width'] = '25mm';
        }
        $decls['display'] = 'block';
        $decls['aspect-ratio'] = '1';
        $label = $n->value() === null ? 'QR code' : 'QR code: ' . $n->value();
        $design = $n->design();
        if ($design !== null) {
            // An image, like other SVG: its gradient ids cannot clash with another code's on the page.
            $xml = BaconQrSvg::svg($n->modules(), $n->quietZoneModules(), $n->level(), $design, $s->color);
            $this->out .= '<img src="data:image/svg+xml;base64,' . base64_encode($xml) . '" alt="' . self::esc($label) . '"' . self::styleAttr($decls) . '>';

            return '';
        }
        $q = $n->quietZoneModules();
        $d = '';
        foreach (ModuleRects::of($n->modules()) as $r) {
            $d .= sprintf('M%d %dh%dv%dh-%dz', $r['x'] + $q, $r['y'] + $q, $r['w'], $r['h'], $r['w']);
        }
        $this->out .= sprintf(
            '<svg viewBox="0 0 %1$d %1$d" role="img" aria-label="%2$s" shape-rendering="crispEdges"%3$s><path d="%4$s" fill="%5$s"/></svg>',
            $n->span(),
            self::esc($label),
            self::styleAttr($decls),
            $d,
            self::esc(Css::color($s->color)),
        );

        return '';
    }

    private function image(Image $n, ComputedStyle $s, Visit $at): string
    {
        $decls = Css::box($s);
        if ($this->parentIsFlex($at)) {
            $decls += Css::flexItem($s);
        }
        $decls['object-fit'] = $n->fitMode()->value;
        $this->out .= '<img src="' . self::esc(ImageSource::uri($n, $this->options->imageUrl(), $this->backend, fn () => $this->fidelity->warn(sprintf('[%s] imageUrl returned an unsafe URL; the image was embedded instead', $at->path())), $this->loadImage(...))) . '" alt=""' . self::styleAttr($decls) . '>';

        return '';
    }

    /**
     * An img with a data URI, never inline markup: a browser runs no script and fetches nothing
     * for an SVG image. Its text is set in the browser's fonts, which cannot be the page's.
     */
    private function svg(Svg $n, ComputedStyle $s, Visit $at): string
    {
        $svg = $n->document();
        $decls = Css::box($s);
        if ($this->parentIsFlex($at)) {
            $decls += Css::flexItem($s);
        }
        if ($svg->hasText) {
            $this->fidelity->approximated('SVG text in the browser\'s own fonts', $at->path());
        }
        $this->out .= '<img src="data:image/svg+xml;base64,' . base64_encode($svg->xml) . '" alt="' . self::esc($svg->title) . '"' . self::styleAttr($decls) . '>';

        return '';
    }

    /** Positioned in the page section; a percentage of the page height is of the section's height. */
    private function place(Place $n): string
    {
        $css = static fn (Length|Percent $v): string => $v instanceof Percent ? (string) $v : Css::pt($v->points);
        $anchor = $n->anchorPoint();
        $width = $n->boxWidth();
        $decls = ['position' => 'absolute', 'left' => $css($n->x()), 'top' => $css($n->y())];
        if ($width !== null) {
            $decls['width'] = $css($width);
        }
        if ($anchor->across() !== 0.0 || $anchor->down() !== 0.0) {
            $shift = static fn (float $f): string => $f === 0.0 ? '0' : '-' . ($f * 100) . '%';
            $decls['transform'] = 'translate(' . $shift($anchor->across()) . ',' . $shift($anchor->down()) . ')';
        }
        $this->out .= '<div' . self::styleAttr($decls) . '>';

        return '</div>';
    }

    /** A browser cannot draw a page of a PDF inline, so it is left out. */
    private function pdfPage(Visit $at): string
    {
        $this->fidelity->dropped('PDF page', $at->path());

        return '';
    }

    private function table(Table $n, ComputedStyle $s, Visit $at): string
    {
        $parent = end($this->styles);
        \assert($parent instanceof ComputedStyle);
        $this->checkDecoration($s, $parent, $at->path());
        $tracks = WidthResolver::tracks($n);
        $given = $n->trackList()?->count();
        if ($given !== null && $given !== count($tracks)) {
            $this->fidelity->warn(sprintf('[%s] Table has %d columns but %d tracks', $at->path(), count($tracks), $given));
        }
        $fixed = 0.0;
        $percent = 0.0;
        $frs = 0.0;
        foreach ($tracks as $t) {
            $fixed += $t instanceof Length ? $t->points : 0.0;
            $percent += $t instanceof Percent ? $t->value : 0.0;
            $frs += $t instanceof Fr ? $t->value : 0.0;
        }
        if ($frs > 0.0) {
            $this->fidelity->approximated('fr column widths', $at->path());
        }
        $est = max(0.0, $this->regionWidth - array_sum($this->edges) - Css::horizontalEdges($s));
        // a table that fits its columns (markup tables): Length columns too wide for the page are scaled down together
        $shrink = 1.0;
        if ($n->fitsColumns() && $est > 0.0 && $fixed > 0.0) {
            $room = $est * max(0.0, 1.0 - $percent / 100.0);
            $shrink = $fixed > $room ? $room / $fixed : 1.0;
        }
        $hasAuto = in_array(true, array_map(static fn (object $t): bool => $t instanceof Auto, $tracks), true);
        if ($hasAuto) {
            $this->fidelity->approximated('auto column widths', $at->path());
        }

        $decls = [...Css::box($s), ...$this->textCss($s, $parent, $at->path())];
        if ($this->parentIsFlex($at)) {
            $decls += Css::flexItem($s);
        }
        if ($hasAuto) {
            $decls['table-layout'] = 'auto';
        }
        if ($n->fillsWidth() && !isset($decls['width'])) {
            $decls['width'] = '100%';
        }
        $this->out .= '<table' . self::styleAttr($decls) . '><colgroup>';
        foreach ($tracks as $t) {
            $width = match (true) {
                $t instanceof Length => Css::pt($t->points * $shrink),
                $t instanceof Percent => Css::num($t->value) . '%',
                $t instanceof Fr => $hasAuto ? null : self::frWidth($fixed, $percent, $t->value / $frs, $est),
                default => null,
            };
            $this->out .= '<col' . self::styleAttr($width === null ? [] : ['width' => $width]) . '>';
        }
        $this->out .= '</colgroup>';
        $this->sections[] = null;

        return '</table>';
    }

    /** A plain percentage of the table: browsers ignore a calc() mixing % and pt on a col, so fixed tracks are converted at the estimated width. */
    private static function frWidth(float $fixed, float $percent, float $share, float $estimate): string
    {
        $fixedPercent = $estimate > 0.0 ? $fixed / $estimate * 100 : 0.0;

        return Css::num($share * max(0.0, 100 - $percent - $fixedPercent)) . '%';
    }

    private function tableRow(TableRow $n, ComputedStyle $s, Visit $at): string
    {
        if (!$at->parent() instanceof Table) {
            throw new \LogicException(sprintf('No layouter registered for %s at "%s".', TableRow::class, $at->path()));
        }
        $parent = end($this->styles);
        \assert($parent instanceof ComputedStyle);
        $this->checkDecoration($s, $parent, $at->path());
        $this->rowPlacements ??= new \SplObjectStorage();
        $this->rowPlacements[$n] = TableGrid::of($at->parent())->placements[$at->index()] ?? [];
        $want = $at->inHeader() ? 'thead' : 'tbody';
        $open = end($this->sections);
        if ($open !== $want) {
            $this->out .= ($open === null ? '' : '</' . $open . '>') . '<' . $want . '>';
            array_pop($this->sections);
            $this->sections[] = $want;
        }
        $box = Css::box($s);
        if (isset($box['padding'])) {
            unset($box['padding']);
            $this->fidelity->approximated('row padding', $at->path());
        }
        $this->out .= '<tr' . self::styleAttr([...$box, ...$this->textCss($s, $parent, $at->path())]) . '>';

        return '</tr>';
    }

    private function tableCell(TableCell $n, ComputedStyle $s, Visit $at): string
    {
        $parent = end($this->styles);
        \assert($parent instanceof ComputedStyle);
        if (!$at->parent() instanceof TableRow) {
            return $this->strayCell($n, $s, $parent, $at);
        }
        $this->checkDecoration($s, $parent, $at->path());
        $tag = $n->header() ? 'th' : 'td';
        $p = $this->rowPlacements[$at->parent()][$at->index()] ?? null;
        $colspan = $p->colspan ?? $n->span();
        $rowspan = $p->rowspan ?? $n->rowsSpanned();
        $span = ($colspan > 1 ? ' colspan="' . $colspan . '"' : '') . ($rowspan > 1 ? ' rowspan="' . $rowspan . '"' : '');
        $td = [...Css::box($s), ...$this->textCss($s, $parent, $at->path())];
        if ($s->justify === Justify::Center) {
            $td['vertical-align'] = 'middle';
        } elseif ($s->justify === Justify::End) {
            $td['vertical-align'] = 'bottom';
        } elseif ($s->justify === Justify::SpaceBetween || $s->justify === Justify::SpaceAround || $s->justify === Justify::SpaceEvenly) {
            $this->fidelity->approximated('cell justify', $at->path());
        }
        // The cell's children are laid out as a column; a td cannot be a flex container, so a div inside it is.
        $column = array_diff_key(Css::flexContainer($s), ['display' => 1, 'flex-direction' => 1]);
        $this->out .= '<' . $tag . $span . self::styleAttr($td) . '><div' . self::styleAttr(['display' => 'flex', 'flex-direction' => 'column', ...$column]) . '>';

        return '</div></' . $tag . '>';
    }

    /** A cell outside a table is laid out as a column; it becomes a div and warns like the PDF layout does. */
    private function strayCell(TableCell $n, ComputedStyle $s, ComputedStyle $parent, Visit $at): string
    {
        if (!isset($this->strays[$n])) {
            $this->strays[$n] = true;
            $this->fidelity->warn(sprintf('[%s] A table cell outside a table is laid out as a column', $at->path()));
        }
        $this->checkDecoration($s, $parent, $at->path());
        $decls = [...Css::box($s), ...($this->parentIsFlex($at) ? Css::flexItem($s) : []), ...Css::flexContainer($s), ...Css::pagination($s, $parent), ...$this->textCss($s, $parent, $at->path())];
        $this->out .= '<div' . self::styleAttr($decls) . '>';

        return '</div>';
    }

    /** True when the parent is a flow written with columns (not a markup flow that fell back to one column). */
    private function parentIsColumnFlow(Visit $at): bool
    {
        $p = $at->parent();

        return $p instanceof View && ColumnFlows::isFlow($p) && $this->flowFrames !== [] && end($this->flowFrames) !== null;
    }

    /** Flex items only make sense inside flex containers: Views, Links and region roots. */
    private function parentIsFlex(Visit $at): bool
    {
        $p = $at->parent();
        if ($this->parentIsColumnFlow($at)) {
            return false;
        }

        return $p === null || $p instanceof View || $p instanceof Link || $p instanceof TableCell;
    }

    /** CSS cannot switch off a decoration an ancestor drew, so turning one off is only approximated. */
    private function checkDecoration(ComputedStyle $s, ComputedStyle $parent, string $path): void
    {
        if (self::overridesDecoration($s, $parent)) {
            $this->fidelity->approximated('text decoration override', $path);
        }
    }

    private static function overridesDecoration(ComputedStyle $s, ComputedStyle $parent): bool
    {
        return ($parent->underline && !$s->underline) || ($parent->strike && !$s->strike);
    }

    private static function href(string $uri): string
    {
        if (!\Papercut\Paint\Link::allowed($uri)) {
            throw InvalidValue::for('link', $uri)->because('only http, https, mailto, tel and #fragment links are allowed');
        }

        return ltrim($uri);
    }

    /** @param array<string,string> $decls */
    private static function attr(array $decls): string
    {
        return self::esc(Css::attr($decls));
    }

    /** @param array<string,string> $decls */
    private static function styleAttr(array $decls): string
    {
        return $decls === [] ? '' : ' style="' . self::attr($decls) . '"';
    }
}
