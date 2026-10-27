<?php

declare(strict_types=1);

namespace Papercut\Layout;

use Papercut\{Component, Image, Link, ListNode, Node, OverflowMode, PageBreak, PageNumber, PdfPage, Place, QrCode, Spacer, Svg, Table, TableCell, Text, View};
use Papercut\Exception\{InvalidValue, UnknownAttribute};
use Papercut\Paint\Bookmark;
use Papercut\Style\{ComputedStyle, Direction};
use Papercut\Tree\{Expander, NodePath};

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

    private readonly BlockLayouter $block;
    private readonly FlexLayouter $flex;
    private readonly TableLayouter $table;

    public function __construct(private readonly LayoutContext $ctx)
    {
        $this->intrinsics = new \WeakMap();
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
        $node = Expander::expand($node);
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
            if ($result->status === Status::DoesNotFit && $c->fresh) {
                $clipped = $c->height !== null && $this->ctx->overflow === OverflowMode::Clip
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

    /** @return array{min: float, max: float} */
    public function intrinsic(Node|Component $node, ComputedStyle $parent, ?int $index = null): array
    {
        $node = Expander::expand($node);
        $byParent = $this->intrinsics[$node] ?? null;
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
                $this->intrinsics[$node] = $byParent;
            }
            $byParent[$parent] = $size;
            return $size;
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
        $heading = $node instanceof Text && in_array($node->preset(), ['h1', 'h2', 'h3', 'h4'], true) ? (int) substr((string) $node->preset(), 1) : null;
        $title = $style->bookmark ?? ($heading !== null ? self::plainText($node) : null);
        $title = $title === null ? '' : trim((string) preg_replace('/\s+/u', ' ', $title));
        if ($title === '') {
            return null;
        }
        return new Bookmark($style->bookmarkLevel ?? $heading ?? 1, $title, 0.0, 0.0);
    }

    private static function plainText(Node $node): string
    {
        if (!$node instanceof Text) {
            return '';
        }
        return implode('', array_map(static fn (\Papercut\Span $s): string => $s->lineBreak ? ' ' : $s->text, $node->spanList()));
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
