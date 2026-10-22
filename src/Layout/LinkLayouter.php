<?php

declare(strict_types=1);

namespace Papercut\Layout;

use Papercut\{Link, Node};
use Papercut\Paint\Link as LinkOp;
use Papercut\Style\ComputedStyle;

/** A Link lays out like a column block and adds a link annotation over each fragment. */
final class LinkLayouter implements Layouter
{
    public function __construct(private readonly BlockLayouter $block)
    {
    }

    public function layout(Node $node, ComputedStyle $style, Constraints $c, ?BreakToken $token, LayoutEngine $engine): LayoutResult
    {
        if (!$node instanceof Link) {
            throw new \LogicException(sprintf('LinkLayouter cannot lay out %s.', $node::class));
        }
        $res = $this->block->layout($node, $style, $c, $token, $engine);
        $res->fragment?->addOp(new LinkOp(0.0, 0.0, $res->fragment->width, $res->fragment->height, $node->uri()));
        return $res;
    }

    public function intrinsic(Node $node, ComputedStyle $style, LayoutEngine $engine): array
    {
        return $this->block->intrinsic($node, $style, $engine);
    }
}
