<?php

declare(strict_types=1);

namespace Papercut\Layout;

use Papercut\{ListNode, Node, Text, View};
use Papercut\Style\{ComputedStyle, ListMarker};

/**
 * Lays out a ListNode as a column of MarkedItems: the block layouter does the
 * stacking, gaps and page splits; each item reserves a gutter as wide as the
 * widest marker label (each measured in its item's style) plus half the
 * list's font size.
 */
final class ListLayouter implements Layouter
{
    /**
     * Synthesized columns per list, computed style and depth, so the same
     * MarkedItems (and their break tokens) are reused across pages.
     *
     * @var \WeakMap<ListNode, \WeakMap<ComputedStyle, array<int, View>>>
     */
    private \WeakMap $columns;

    public function __construct()
    {
        $this->columns = new \WeakMap();
    }

    public function layout(Node $node, ComputedStyle $style, Constraints $c, ?BreakToken $token, LayoutEngine $engine): LayoutResult
    {
        $list = self::list($node);
        $depth = $engine->context()->enterList($list->isOrdered());
        try {
            return $engine->blockLayouter()->layout($this->column($list, $style, $depth, $engine), $style, $c, $token, $engine);
        } finally {
            $engine->context()->leaveList($list->isOrdered());
        }
    }

    public function intrinsic(Node $node, ComputedStyle $style, LayoutEngine $engine): array
    {
        $list = self::list($node);
        $depth = $engine->context()->enterList($list->isOrdered());
        try {
            return $engine->blockLayouter()->intrinsic($this->column($list, $style, $depth, $engine), $style, $engine);
        } finally {
            $engine->context()->leaveList($list->isOrdered());
        }
    }

    /** Space between the marker's right edge and the item content, from the list's font size. */
    private static function gap(ComputedStyle $style): float
    {
        return $style->fontSize / 2;
    }

    private function column(ListNode $list, ComputedStyle $style, int $depth, LayoutEngine $engine): View
    {
        $byStyle = $this->columns[$list] ?? null;
        if ($byStyle === null) {
            /** @var \WeakMap<ComputedStyle, array<int, View>> $byStyle */
            $byStyle = new \WeakMap();
            $this->columns[$list] = $byStyle;
        }
        $byDepth = $byStyle[$style] ?? [];
        if (isset($byDepth[$depth])) {
            return $byDepth[$depth];
        }

        $marker = $list->markerStyle() ?? ListMarker::forDepth($list->isOrdered(), $depth);
        $labels = array_map(static fn (int $n): string => $marker->label($n), $list->numbers());
        // each label is measured in its item's style, the style it is painted in
        $widest = 0.0;
        foreach ($list->items() as $i => $item) {
            if ($labels[$i] !== '') {
                $widest = max($widest, $engine->intrinsic(Text::make($labels[$i]), $engine->styleOf($item, $style, $i))['max']);
            }
        }
        $gap = $marker === ListMarker::None ? 0.0 : self::gap($style);
        $gutter = $marker === ListMarker::None ? 0.0 : $widest + $gap;

        $items = [];
        foreach ($list->items() as $i => $item) {
            $items[] = new MarkedItem($item, $labels[$i], $gutter, $gap);
        }
        $byDepth[$depth] = View::make(...$items);
        $byStyle[$style] = $byDepth;
        return $byDepth[$depth];
    }

    private static function list(Node $node): ListNode
    {
        if (!$node instanceof ListNode) {
            throw new \LogicException(sprintf('ListLayouter cannot lay out %s.', $node::class));
        }
        return $node;
    }
}
