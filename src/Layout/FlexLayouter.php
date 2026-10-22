<?php

declare(strict_types=1);

namespace Papercut\Layout;

use Papercut\{Node, PageBreak, Spacer, View};
use Papercut\Style\{Align, ComputedStyle};
use Papercut\Tree\Expander;
use Papercut\Value\{Auto, Length};

/**
 * Row-direction Views: CSS flexbox (simplified) along the x axis, with
 * optional wrapping into flex lines.
 *
 * Each flex line fragments as a unit: it moves to the next page whole, or,
 * when it is first on a fresh page and still too tall, its items split in
 * parallel. The token then holds one entry per item (null = finished); on
 * resume finished items keep their slot but draw nothing.
 */
final class FlexLayouter implements Layouter
{
    private const float EPSILON = 1e-9;

    public function layout(Node $node, ComputedStyle $style, Constraints $c, ?BreakToken $token, LayoutEngine $engine): LayoutResult
    {
        $first = $token === null;
        $borderWidth = BoxGeometry::borderBoxWidth($style, $c->width);
        $contentWidth = BoxGeometry::contentWidth($style, $c->width);

        // Only an explicit height is monolithic; a stretched definiteHeight is a minimum fill and stays splittable.
        $target = $first ? BoxGeometry::definiteHeight($style, $c) : null;
        $fixed = $target !== null && !$style->height instanceof Auto ? $target : null;
        if ($fixed !== null && $c->height !== null && $fixed > $c->height + self::EPSILON) {
            return LayoutResult::doesNotFit();
        }
        if ($first && $fixed === null) {
            // min-height is a minimum fill, like a stretch: the box grows to it but can still split.
            $floor = BoxGeometry::minimumFill($style, $c);
            if ($floor === false) {
                return LayoutResult::doesNotFit();
            }
            if ($floor !== null) {
                $target = max($target ?? 0.0, $floor);
            }
        }
        $fixedContent = $target === null ? null : max(0.0, $target - BoxGeometry::topEdge($style) - BoxGeometry::bottomEdge($style));

        // A content-sized box whose max-height fits the space is laid out whole and capped (taller ones split).
        $capped = $first && $fixed === null && BoxGeometry::capsWhole($style, $c);
        $available = null;
        if ($fixed === null && !$capped && $c->height !== null) {
            $available = $c->height - ($first ? BoxGeometry::topEdge($style) : 0.0) - BoxGeometry::bottomEdge($style);
        }

        $items = $this->items($node, $style, $contentWidth, $engine, $token === null);
        $lines = self::resolveLines($items, $style, $contentWidth);
        $count = count($lines);

        /** @var list<array{x: float, y: float, fragment: Fragment}> $placed */
        $placed = [];
        $cursor = 0.0;
        $start = min(max(0, $token->index ?? 0), $count);
        $breakAt = null;
        $linesOnPage = 0;

        for ($k = $start; $k < $count; $k++) {
            $line = $lines[$k];
            $gap = $linesOnPage === 0 ? 0.0 : $style->gap;
            $fresh = $c->fresh && $linesOnPage === 0;
            $resuming = $k === $start && $token !== null && $token->parallel !== [];

            if (!$resuming) {
                $crossFill = $fixedContent !== null && !$style->wrap ? $fixedContent : null;
                $measured = $this->measureLine($line, $style, $fresh, $crossFill, $engine);
                if ($available === null || $cursor + $gap + $measured['height'] <= $available + self::EPSILON) {
                    foreach ($measured['cells'] as $cell) {
                        $placed[] = ['x' => $cell['x'], 'y' => $cursor + $gap + $cell['y'], 'fragment' => $cell['fragment']];
                    }
                    $cursor += $gap + $measured['height'];
                    $linesOnPage++;
                    continue;
                }
                if ($linesOnPage > 0) {
                    $breakAt = new BreakToken(index: $k);
                    break;
                }
                if (!$c->fresh) {
                    return LayoutResult::doesNotFit();
                }
            }

            $tokens = $resuming ? $token->parallel : null;
            $room = $available === null ? null : max(0.0, $available - $cursor - $gap);
            $split = $this->splitLine($line, $style, $room, $fresh, $tokens, $engine);
            if ($split === null) {
                return LayoutResult::doesNotFit();
            }
            foreach ($split['cells'] as $cell) {
                $placed[] = ['x' => $cell['x'], 'y' => $cursor + $gap + $cell['y'], 'fragment' => $cell['fragment']];
            }
            $cursor += $gap + $split['height'];
            $linesOnPage++;
            if (ParallelSplit::pending($split['tokens'])) {
                $breakAt = new BreakToken(index: $k, parallel: $split['tokens']);
                break;
            }
        }

        if ($breakAt !== null && $style->keepTogether && !$c->fresh) {
            return LayoutResult::doesNotFit();
        }

        $last = $breakAt === null;
        $contentHeight = $fixed !== null ? (float) $fixedContent : ($last ? max($cursor, $fixedContent ?? 0.0) : $cursor);
        if ($fixed === null && $first && $last) {
            $contentHeight = BoxGeometry::capContent($style, $c, $contentHeight);
        }
        if ($capped) {
            $fit = BoxGeometry::fitContent($style, $c, $contentHeight);
            if ($fit === null) {
                return LayoutResult::doesNotFit();
            }
            $contentHeight = $fit;
        }
        $f = new Fragment($borderWidth, BoxGeometry::outerHeight($style, $contentHeight, $first, $last));
        BoxGeometry::decorate($f, $style, $first, $last);
        $ox = $style->borderWidth->left->points + $style->padding->left->points;
        $oy = $first ? BoxGeometry::topEdge($style) : 0.0;
        foreach ($placed as $p) {
            $f->addChild($ox + $p['x'], $oy + $p['y'], $p['fragment']);
        }

        return $breakAt === null ? LayoutResult::complete($f) : LayoutResult::partial($f, $breakAt);
    }

    public function intrinsic(Node $node, ComputedStyle $style, LayoutEngine $engine): array
    {
        if ($style->width instanceof Length) {
            return ['min' => $style->width->points, 'max' => $style->width->points];
        }
        $min = 0.0;
        $sumMin = 0.0;
        $max = 0.0;
        $n = 0;
        foreach (self::children($node) as $i => $child) {
            $child = Expander::expand($child);
            if ($child instanceof PageBreak) {
                continue;
            }
            $margin = $engine->styleOf($child, $style, $i)->margin->horizontal();
            $size = $engine->intrinsic($child, $style, $i);
            $min = max($min, $size['min'] + $margin);
            $sumMin += $size['min'] + $margin;
            $max += $size['max'] + $margin;
            $n++;
        }
        $gaps = $style->gap * max(0, $n - 1);
        $edges = $style->padding->horizontal() + $style->borderWidth->horizontal();
        return [
            'min' => ($style->wrap ? $min : $sumMin + $gaps) + $edges,
            'max' => $max + $gaps + $edges,
        ];
    }

    /**
     * Flex lines for a row whose border box is offered $available, without
     * pagination: the same items and line resolution layout() uses.
     *
     * @return list<list<array{node: Node, style: ComputedStyle, index: int, x: float, main: float}>>
     */
    public function lines(Node $node, ComputedStyle $style, float $available, LayoutEngine $engine): array
    {
        $contentWidth = BoxGeometry::contentWidth($style, $available);
        return self::resolveLines($this->items($node, $style, $contentWidth, $engine), $style, $contentWidth);
    }

    /**
     * Flex items with their hypothetical main sizes (border box) and clamps.
     *
     * @return list<array{node: Node, style: ComputedStyle, index: int, base: float, hyp: float, min: float, max: float, margin: float}>
     */
    private function items(Node $node, ComputedStyle $style, float $width, LayoutEngine $engine, bool $warn = false): array
    {
        $items = [];
        foreach (self::children($node) as $i => $child) {
            $child = Expander::expand($child);
            if ($child instanceof PageBreak) {
                if ($warn) {
                    $engine->context()->warn('PageBreak inside a row is ignored');
                }
                continue;
            }
            $cs = $engine->styleOf($child, $style, $i);
            $explicit = BoxGeometry::resolve($cs->basis, $width) ?? BoxGeometry::resolve($cs->width, $width);
            $intrinsic = $explicit === null ? $engine->intrinsic($child, $style, $i) : null;
            $base = $explicit ?? ($child instanceof Spacer && $child->fixedSize() === null ? 0.0 : $intrinsic['max'] ?? 0.0);
            $min = BoxGeometry::resolve($cs->minWidth, $width) ?? ($intrinsic === null || $child instanceof Spacer ? 0.0 : $intrinsic['min']);
            $max = BoxGeometry::resolve($cs->maxWidth, $width) ?? \INF;
            $items[] = [
                'node' => $child,
                'style' => $cs,
                'index' => $i,
                'base' => $base,
                'hyp' => max($min, min($max, $base)),
                'min' => $min,
                'max' => $max,
                'margin' => $cs->margin->horizontal(),
            ];
        }
        return $items;
    }

    /**
     * Breaks items into lines, resolves flexible lengths and justifies.
     *
     * @param list<array{node: Node, style: ComputedStyle, index: int, base: float, hyp: float, min: float, max: float, margin: float}> $items
     * @return list<list<array{node: Node, style: ComputedStyle, index: int, x: float, main: float}>>
     */
    private static function resolveLines(array $items, ComputedStyle $style, float $width): array
    {
        $groups = [];
        $current = [];
        $used = 0.0;
        foreach ($items as $item) {
            $outer = $item['hyp'] + $item['margin'];
            $next = $current === [] ? $outer : $used + $style->gap + $outer;
            if ($style->wrap && $current !== [] && $next > $width + self::EPSILON) {
                $groups[] = $current;
                $current = [];
                $next = $outer;
            }
            $current[] = $item;
            $used = $next;
        }
        if ($current !== []) {
            $groups[] = $current;
        }

        $lines = [];
        foreach ($groups as $group) {
            $sizes = self::flex($group, $width - $style->gap * (count($group) - 1));
            $n = count($group);
            $free = $width - $style->gap * ($n - 1);
            foreach ($group as $j => $item) {
                $free -= $sizes[$j] + $item['margin'];
            }
            [$lead, $between] = BoxGeometry::justify($style->justify, $free, $n);

            $line = [];
            $x = $lead;
            foreach ($group as $j => $item) {
                $line[] = [
                    'node' => $item['node'],
                    'style' => $item['style'],
                    'index' => $item['index'],
                    'x' => $x + $item['style']->margin->left->points,
                    'main' => $sizes[$j],
                ];
                $x += $sizes[$j] + $item['margin'] + $style->gap + $between;
            }
            $lines[] = $line;
        }
        return $lines;
    }

    /**
     * Grows or shrinks one line's items to fill $space (width minus gaps).
     * One clamp pass: items that hit min/max are frozen and the rest is
     * redistributed once.
     *
     * @param list<array{node: Node, style: ComputedStyle, index: int, base: float, hyp: float, min: float, max: float, margin: float}> $items
     * @return list<float>
     */
    private static function flex(array $items, float $space): array
    {
        $sizes = array_column($items, 'hyp');
        $frozen = [];
        for ($pass = 0; $pass < 2; $pass++) {
            $free = $space;
            foreach ($items as $j => $item) {
                $free -= (isset($frozen[$j]) ? $sizes[$j] : $item['hyp']) + $item['margin'];
            }
            $growing = $free > 0.0;
            $weights = [];
            foreach ($items as $j => $item) {
                if (!isset($frozen[$j])) {
                    $weights[$j] = $growing ? $item['style']->grow : $item['style']->shrink * $item['base'];
                }
            }
            $total = array_sum($weights);
            if (abs($free) <= self::EPSILON || $total <= 0.0) {
                foreach ($weights as $j => $_) {
                    $sizes[$j] = $items[$j]['hyp'];
                }
                break;
            }
            $clamped = false;
            foreach ($weights as $j => $w) {
                $target = $items[$j]['hyp'] + $free * $w / $total;
                $sizes[$j] = max($items[$j]['min'], min($items[$j]['max'], $target));
                if (abs($sizes[$j] - $target) > self::EPSILON) {
                    $frozen[$j] = true;
                    $clamped = true;
                }
            }
            if (!$clamped) {
                break;
            }
        }
        return array_values(array_map(static fn (float $s): float => max(0.0, $s), $sizes));
    }

    /**
     * Lays out a line at unbounded height, then stretches Auto-height items
     * to the line's cross size and aligns the rest.
     *
     * @param list<array{node: Node, style: ComputedStyle, index: int, x: float, main: float}> $line
     * @return array{height: float, cells: list<array{x: float, y: float, fragment: Fragment}>}
     */
    private function measureLine(array $line, ComputedStyle $style, bool $fresh, ?float $crossFill, LayoutEngine $engine): array
    {
        ['height' => $height, 'fragments' => $frags] = ParallelSplit::measure(
            array_keys($line),
            $crossFill ?? 0.0,
            fn (int $j): Fragment => $this->layoutItem($line[$j], $style, new Constraints($line[$j]['main'], null, $fresh), null, $engine)->fragment
                ?? new Fragment($line[$j]['main'], 0.0),
            static fn (int $j, Fragment $f): float => $f->height + $line[$j]['style']->margin->vertical(),
        );

        $cells = [];
        foreach ($line as $j => $item) {
            $cs = $item['style'];
            $align = $cs->alignSelf ?? $style->alignItems;
            $f = $frags[$j];
            $slot = $height - $cs->margin->vertical();
            if ($align === Align::Stretch && $cs->height instanceof Auto && $f->height < $slot - self::EPSILON) {
                $f = $this->layoutItem($item, $style, new Constraints($item['main'], null, $fresh, $slot), null, $engine)->fragment ?? $f;
            }
            $cells[] = ['x' => $item['x'], 'y' => $cs->margin->top->points + self::alignY($align, $slot, $f->height), 'fragment' => $f];
        }
        return ['height' => $height, 'cells' => $cells];
    }

    /**
     * Parallel split of one line in $height (see ParallelSplit::split); items
     * keep their margins, the trailing one truncated at $height.
     *
     * @param list<array{node: Node, style: ComputedStyle, index: int, x: float, main: float}> $line
     * @param ?array<int, ?BreakToken> $tokens
     * @return ?array{height: float, tokens: array<int, ?BreakToken>, cells: list<array{x: float, y: float, fragment: Fragment}>}
     */
    private function splitLine(array $line, ComputedStyle $style, ?float $height, bool $fresh, ?array $tokens, LayoutEngine $engine): ?array
    {
        $slots = array_map(static fn (array $item): array => [
            'x' => $item['x'],
            'top' => $item['style']->margin->top->points,
            'bottom' => $item['style']->margin->bottom->points,
        ], $line);
        return ParallelSplit::split(
            $slots,
            $height,
            $tokens,
            fn (int $j, ?float $room, ?BreakToken $resume): LayoutResult => $this->layoutItem($line[$j], $style, new Constraints($line[$j]['main'], $room, $fresh), $resume, $engine),
        );
    }

    /**
     * Lays an item out at exactly its resolved main size.
     *
     * @param array{node: Node, style: ComputedStyle, index: int, x: float, main: float} $item
     */
    private function layoutItem(array $item, ComputedStyle $style, Constraints $c, ?BreakToken $token, LayoutEngine $engine): LayoutResult
    {
        return ExactWidth::layout($item['node'], $item['main'], $style, $c, $token, $engine, $item['index'], $item['style']);
    }

    private static function alignY(Align $align, float $slot, float $height): float
    {
        return match ($align) {
            Align::Center => ($slot - $height) / 2,
            Align::End => $slot - $height,
            Align::Start, Align::Stretch => 0.0,
        };
    }

    /** @return list<\Papercut\Node|\Papercut\Component> */
    private static function children(Node $node): array
    {
        if ($node instanceof View) {
            return $node->children();
        }
        throw new \LogicException(sprintf('FlexLayouter cannot lay out %s.', $node::class));
    }
}
