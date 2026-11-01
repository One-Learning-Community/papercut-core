<?php

declare(strict_types=1);

namespace Papercut\Layout;

use Papercut\{Component, Image, Link, ListNode, Node, OverflowMode, PageBreak, PageNumber, PdfPage, Place, QrCode, Spacer, Svg, Table, TableCell, Text, Toc, View};
use Papercut\Exception\{InvalidValue, UnknownAttribute};
use Papercut\Paint\{Anchor, Bookmark};
use Papercut\Style\{ComputedStyle, Direction};
use Papercut\Toc\{HeadingText, TocLine};
use Papercut\Tree\{Expander, NodePath};
use Papercut\Value\Auto;

/**
 * Dispatches nodes to their layouters. Expands Components, resolves the
 * node's computed style and maintains the context's node path.
 */
final class LayoutEngine
{
    /** @var array<class-string<Node>, Layouter> */
    private array $layouters = [];

    /**
     * Intrinsic sizes memoized per node and parent style (by identity) for this
     * engine's layout run. Nodes are immutable and their computed style is a
     * function of node and parent, so the sizes are too.
     *
     * @var \WeakMap<Node, \WeakMap<ComputedStyle, array{min: float, max: float}>>
     */
    private \WeakMap $intrinsics;
    /** @var \WeakMap<Node, \WeakMap<ComputedStyle, array{min: float, max: float}>> the same while sizes are measured as if text breaks anywhere */
    private \WeakMap $anywhereIntrinsics;
    private bool $anywhere = false;

    private readonly BlockLayouter $block;
    private readonly FlexLayouter $flex;
    private readonly TableLayouter $table;

    public function __construct(private readonly LayoutContext $ctx)
    {
        $this->intrinsics = new \WeakMap();
        $this->anywhereIntrinsics = new \WeakMap();
        $this->block = new BlockLayouter();
        $this->flex = new FlexLayouter();
        $leaf = new LeafLayouter();
        $this->layouters[Text::class] = new TextLayouter();
        $this->layouters[Link::class] = new LinkLayouter($this->block);
        $this->layouters[Image::class] = new ImageLayouter();
        $this->layouters[QrCode::class] = new QrCodeLayouter();
        $this->layouters[Svg::class] = new SvgLayouter();
        $this->layouters[PdfPage::class] = new PdfPageLayouter();
        $this->layouters[Place::class] = new PlaceLayouter();
        $this->table = new TableLayouter($this->block);
        $this->layouters[Table::class] = $this->table;
        $this->layouters[TableCell::class] = $this->table;
        $this->layouters[Spacer::class] = $leaf;
        $this->layouters[PageBreak::class] = $leaf;
        $this->layouters[PageNumber::class] = $leaf;
        $this->layouters[ListNode::class] = new ListLayouter();
        $this->layouters[MarkedItem::class] = new MarkedItemLayouter();
        $toc = new TocLayouter($this->block);
        $this->layouters[Toc::class] = $toc;
        $this->layouters[TocLine::class] = $toc;
    }

    public function blockLayouter(): BlockLayouter
    {
        return $this->block;
    }

    public function flexLayouter(): FlexLayouter
    {
        return $this->flex;
    }

    public function tableLayouter(): TableLayouter
    {
        return $this->table;
    }

    /** Renders components down to a node, with the current page's theme for those that depend on it. */
    public function expand(Node|Component $node): Node
    {
        return Expander::expand($node, $this->ctx->theme);
    }

    public function context(): LayoutContext
    {
        return $this->ctx;
    }

    /**
     * $index is the node's position among its siblings; it is shown in the
     * path, e.g. "column[2]". $pathName replaces the node's path segment
     * (the paginator names the page body "body").
     */
    public function layout(Node|Component $node, ComputedStyle $parent, Constraints $c, ?BreakToken $token = null, ?int $index = null, ?string $pathName = null): LayoutResult
    {
        $node = $this->expand($node);
        $this->ctx->pushPath($pathName ?? NodePath::segment($node, null, $index));
        try {
            $style = $this->enter($node, $parent, $index, $pathName);
            $layouter = $this->layouterFor($node, $style);
            $result = $layouter->layout($node, $style, $c, $token, $this);
            if ($token === null && $this->ctx->bookmarks && $result->fragment !== null) {
                $bookmark = self::bookmark($node, $style);
                if ($bookmark !== null) {
                    $result->fragment->addOp($bookmark);
                }
            }
            if ($token === null && $this->ctx->anchors && $this->ctx->toc->hasToc() && $result->fragment !== null) {
                $entry = $this->ctx->toc->forKey($this->ctx->path());
                if ($entry !== null) {
                    $result->fragment->addOp(new Anchor($entry->destination, 0.0, 0.0));
                }
            }
            if ($result->status === Status::DoesNotFit && $c->fresh) {
                $clipped = $c->height !== null && $this->ctx->overflow === OverflowMode::Clip && $this->ctx->clipInPlace
                    ? $this->clipped($layouter, $node, $style, $c, $token)
                    : null;
                if ($clipped !== null) {
                    return $clipped;
                }
                $this->recordFailure();
            }
            return $result;
        } finally {
            $this->ctx->popPath();
        }
    }

    /**
     * Test seam and extension point: lays out $class nodes with $layouter.
     *
     * @param class-string<Node> $class
     */
    public function register(string $class, Layouter $layouter): void
    {
        $this->layouters[$class] = $layouter;
        $this->intrinsics = new \WeakMap();
        $this->anywhereIntrinsics = new \WeakMap();
    }

    /**
     * Clip overflow: a node too tall for an empty page is laid out unbounded
     * and clipped to the space it was offered, so its siblings still flow.
     */
    private function clipped(Layouter $layouter, Node $node, ComputedStyle $style, Constraints $c, ?BreakToken $token): ?LayoutResult
    {
        $res = $layouter->layout($node, $style, $c->unbounded(), $token, $this);
        if ($res->status !== Status::Complete || $res->fragment === null || $c->height === null) {
            return null;
        }
        $f = new Fragment($c->width, min($res->fragment->height, $c->height));
        $f->clip = [0.0, 0.0, $c->width, $c->height];
        $f->addChild(0.0, 0.0, $res->fragment);
        $this->ctx->warn(sprintf('Content clipped: "%s" is taller than the available space.', $this->ctx->path()), withPath: false);
        return LayoutResult::complete($f);
    }

    /** Keeps an already recorded descendant failure: the deepest node is the useful one. */
    private function recordFailure(): void
    {
        $path = $this->ctx->path();
        $last = $this->ctx->lastFailurePath;
        if ($last !== null && str_starts_with($last, $path . ' > ')) {
            return;
        }
        $this->ctx->setLastFailurePath($path);
    }

    /**
     * Runs $fn with intrinsic sizes measured as if text may break anywhere (its widest letter per
     * line at least) and images, SVGs and PDF pages may scale down: the narrowest a box can get without its content
     * overflowing it, as with CSS overflow-wrap: anywhere. Fixed widths stay as they are.
     *
     * @template T
     * @param \Closure(): T $fn
     * @return T
     */
    public function breakingAnywhere(\Closure $fn): mixed
    {
        $was = $this->anywhere;
        $this->anywhere = true;
        try {
            return $fn();
        } finally {
            $this->anywhere = $was;
        }
    }

    /** True inside breakingAnywhere(). */
    public function breaksAnywhere(): bool
    {
        return $this->anywhere;
    }

    /** @return array{min: float, max: float} */
    public function intrinsic(Node|Component $node, ComputedStyle $parent, ?int $index = null): array
    {
        $node = $this->expand($node);
        $cache = $this->anywhere ? $this->anywhereIntrinsics : $this->intrinsics;
        $byParent = $cache[$node] ?? null;
        if ($byParent !== null && isset($byParent[$parent])) {
            return $byParent[$parent];
        }
        $this->ctx->pushPath(NodePath::segment($node, null, $index));
        try {
            $style = $this->enter($node, $parent, $index);
            $size = $this->layouterFor($node, $style)->intrinsic($node, $style, $this);
            if ($byParent === null) {
                /** @var \WeakMap<ComputedStyle, array{min: float, max: float}> $byParent */
                $byParent = new \WeakMap();
                $cache[$node] = $byParent;
            }
            $byParent[$parent] = $size;
            return $size;
        } finally {
            $this->ctx->popPath();
        }
    }

    /**
     * The node's intrinsic size with its own width left out: what its content needs, as for a flex item's
     * automatic minimum (CSS `min-width: auto`). A node with no width of its own is measured as usual.
     *
     * @return array{min: float, max: float}
     */
    public function contentIntrinsic(Node|Component $node, ComputedStyle $parent, ?int $index = null): array
    {
        $node = $this->expand($node);
        $this->ctx->pushPath(NodePath::segment($node, null, $index));
        try {
            $style = $this->enter($node, $parent, $index);
            if ($style->width instanceof Auto) {
                return $this->intrinsic($node, $parent, $index);
            }
            $auto = new ComputedStyle(...[...get_object_vars($style), 'width' => Auto::instance()]);

            return $this->layouterFor($node, $auto)->intrinsic($node, $auto, $this);
        } finally {
            $this->ctx->popPath();
        }
    }

    /** Computed style of a child node, with style errors reported at its path. */
    public function styleOf(Node $node, ComputedStyle $parent, ?int $index = null): ComputedStyle
    {
        $this->ctx->pushPath(NodePath::segment($node, null, $index));
        try {
            return $this->enter($node, $parent, $index);
        } finally {
            $this->ctx->popPath();
        }
    }

    /**
     * Resolves the node's style while a provisional path entry is pushed, so
     * style errors name the node, then renames the entry by computed direction.
     */
    private function enter(Node $node, ComputedStyle $parent, ?int $index, ?string $pathName = null): ComputedStyle
    {
        try {
            $style = $this->ctx->resolveStyle($node, $parent);
        } catch (UnknownAttribute | InvalidValue $e) {
            throw $e->within('at ' . $this->ctx->path());
        }
        $this->ctx->popPath();
        $this->ctx->pushPath($pathName ?? NodePath::segment($node, $style, $index));
        return $style;
    }

    /** A heading's automatic bookmark, or the one set with bookmark(); placed on the block's first fragment only. */
    private static function bookmark(Node $node, ComputedStyle $style): ?Bookmark
    {
        $heading = $node instanceof Text ? HeadingText::level($node) : null;
        $title = $style->bookmark ?? ($node instanceof Text && $heading !== null ? HeadingText::plain($node) : null);
        $title = $title === null ? '' : trim((string) preg_replace('/\s+/u', ' ', $title));
        if ($title === '') {
            return null;
        }
        return new Bookmark($style->bookmarkLevel ?? $heading ?? 1, $title, 0.0, 0.0);
    }

    private function layouterFor(Node $node, ComputedStyle $style): Layouter
    {
        if ($node instanceof View) {
            return $style->direction === Direction::Row ? $this->flex : $this->block;
        }
        return $this->layouters[$node::class] ?? throw new \LogicException(sprintf(
            'No layouter registered for %s at "%s".',
            $node::class,
            $this->ctx->path(),
        ));
    }
}
