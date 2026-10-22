<?php

declare(strict_types=1);

namespace Papercut\Layout;

use Papercut\{Link, Node, PageBreak, Spacer, View};
use Papercut\Style\{Align, ComputedStyle, Direction};
use Papercut\Tree\Expander;
use Papercut\Value\Length;

/**
 * Column-direction Views (and Links): children stacked top to bottom with
 * margins and gap, fragmented between children or inside a splitting child.
 *
 * A box with an explicit height is monolithic: it never splits, and its free
 * space goes to grow children, then to justify. An Auto-height box laid out
 * with a definiteHeight (flex stretch) stays splittable; when it completes in
 * a single slice it fills at least that height the same way.
 */
final class BlockLayouter implements Layouter
{
    private const float EPSILON = 1e-9;

    public function layout(Node $node, ComputedStyle $style, Constraints $c, ?BreakToken $token, LayoutEngine $engine): LayoutResult
    {
        $children = self::children($node);
        $count = count($children);
        $first = $token === null;
        $borderWidth = BoxGeometry::borderBoxWidth($style, $c->width);
        $contentWidth = BoxGeometry::contentWidth($style, $c->width);

        $fixed = $first ? BoxGeometry::explicitHeight($style, $c) : null;
        $stretch = $first && $fixed === null ? BoxGeometry::clampHeight($style, $c, $c->definiteHeight) : null;
        if ($first && $fixed === null) {
            // min-height is a minimum fill, like a stretch: the box grows to it but can still split.
            $floor = BoxGeometry::minimumFill($style, $c);
            if ($floor === false) {
                return LayoutResult::doesNotFit();
            }
            if ($floor !== null) {
                $stretch = max($stretch ?? 0.0, $floor);
            }
        }
        if ($fixed !== null && $c->height !== null && $fixed > $c->height + self::EPSILON) {
            return LayoutResult::doesNotFit();
        }

        // A content-sized box whose max-height fits the space is laid out whole and capped (taller ones split).
        $capped = $first && $fixed === null && BoxGeometry::capsWhole($style, $c);
        $available = null;
        if ($fixed === null && !$capped && $c->height !== null) {
            $available = $c->height - ($first ? BoxGeometry::topEdge($style) : 0.0) - BoxGeometry::bottomEdge($style);
        }

        /** @var list<array{node: Node, style: ComputedStyle, index: int, width: float, fresh: bool, token: ?BreakToken, fragment: Fragment, x: float, y: float, height: float, before: float}> $placed */
        $placed = [];
        $cursor = 0.0;
        $start = min(max(0, $token->index ?? 0), $count);
        $resumeToken = $token?->child;
        $monolithic = $fixed !== null || $capped;
        $breakAt = null;

        for ($i = $start; $i < $count; $i++) {
            $child = Expander::expand($children[$i]);
            $resuming = $resumeToken !== null && $i === $start;

            if ($child instanceof PageBreak) {
                if ($monolithic || $i === $count - 1) {
                    continue;
                }
                if ($placed === []) {
                    if ($c->fresh) {
                        continue;
                    }
                    return LayoutResult::doesNotFit();
                }
                $breakAt = new BreakToken(index: $i + 1);
                break;
            }

            $cs = $engine->styleOf($child, $style, $i);
            if ($cs->breakBefore && !$resuming && !$monolithic) {
                if ($placed !== []) {
                    $breakAt = new BreakToken(index: $i);
                    break;
                }
                if (!$c->fresh) {
                    return LayoutResult::doesNotFit();
                }
            }

            $gap = $placed === [] ? 0.0 : $style->gap;
            $mt = $resuming ? 0.0 : $cs->margin->top->points;
            $mb = $cs->margin->bottom->points;
            $ml = $cs->margin->left->points;
            $slot = max(0.0, $contentWidth - $cs->margin->horizontal());
            $align = $cs->alignSelf ?? $style->alignItems;
            $width = $slot;
            if ($align !== Align::Stretch && !$child instanceof Spacer) {
                $width = min($engine->intrinsic($child, $style, $i)['max'], $slot);
            }
            // Inside a monolithic box every child is laid out as if on a fresh page, so leading forced breaks are ignored.
            $fresh = $monolithic || ($c->fresh && $placed === []);
            $height = $available === null ? null : max(0.0, $available - $cursor - $gap - $mt);

            $res = $engine->layout($child, $style, new Constraints($width, $height, $fresh), $resuming ? $resumeToken : null, $i);
            if ($monolithic && $res->status === Status::Partial && $res->fragment !== null && $res->token !== null) {
                $res = LayoutResult::complete(self::continueInPlace($res->fragment, $res->token, $child, $width, $i, $style, $engine));
            }
            if ($res->status === Status::DoesNotFit || $res->fragment === null) {
                if ($monolithic) {
                    // unbounded and fresh: cannot happen for well-behaved layouters, but never drop content silently
                    $engine->context()->warn(sprintf('Content dropped inside fixed-height box: %s[%d]', $child->debugName(), $i));
                    continue;
                }
                if ($placed === []) {
                    return LayoutResult::doesNotFit();
                }
                // keep-with-next: walk back over a chain of keepWithNext children placed just before i.
                // A container ending in keepWithNext is re-laid to break before that trailing chain;
                // if nothing of it would remain, the whole container joins the chain.
                $k = count($placed);
                $next = $i;
                $split = null;
                while ($k > 0 && $placed[$k - 1]['index'] === $next - 1) {
                    $p = $placed[$k - 1];
                    if (!$p['style']->keepWithNext) {
                        if (!self::endsWithKeepWithNext($p['node'], $p['style'], $engine)) {
                            break;
                        }
                        $split = self::splitBeforeTrailingKeep($p, $style, $engine);
                        if ($split !== null) {
                            break;
                        }
                    }
                    $k--;
                    $next = $p['index'];
                }
                if ($split !== null) {
                    $p = $placed[$k - 1];
                    $p['fragment'] = $split[0];
                    $p['height'] = $split[0]->height;
                    $p['x'] = self::alignX($p['style']->alignSelf ?? $style->alignItems, $p['style']->margin->left->points, max(0.0, $contentWidth - $p['style']->margin->horizontal()), $split[0]->width);
                    $placed = [...array_slice($placed, 0, $k - 1), $p];
                    $cursor = $p['y'] + $p['height'];
                    $breakAt = new BreakToken(index: $p['index'], child: $split[1]);
                    break;
                }
                if ($k === 0 && !$c->fresh) {
                    return LayoutResult::doesNotFit();
                }
                if ($k > 0 && $k < count($placed)) {
                    $cursor = $placed[$k]['before'];
                    $breakAt = new BreakToken(index: $placed[$k]['index']);
                    $placed = array_slice($placed, 0, $k);
                    break;
                }
                $breakAt = new BreakToken(index: $i);
                break;
            }

            $f = $res->fragment;
            $before = $cursor;
            $y = $cursor + $gap + $mt;
            $placed[] = [
                'node' => $child,
                'style' => $cs,
                'index' => $i,
                'width' => $width,
                'fresh' => $fresh,
                'token' => $resuming ? $resumeToken : null,
                'fragment' => $f,
                'x' => self::alignX($align, $ml, $slot, $f->width),
                'y' => $y,
                'height' => $f->height,
                'before' => $before,
            ];
            $cursor = $y + $f->height;

            if ($res->status === Status::Partial && $res->token !== null) {
                $breakAt = new BreakToken(index: $i, child: $res->token);
                break;
            }
            // Margin-bottom is truncated at the page end, never pushing the box past c.height.
            $cursor = $available === null ? $cursor + $mb : max($cursor, min($cursor + $mb, $available));
            if (!$monolithic && $i < $count - 1 && self::forcesBreakAfter($child, $cs, $engine)) {
                $breakAt = new BreakToken(index: $i + 1);
                break;
            }
        }

        if ($breakAt !== null && $style->keepTogether && !$c->fresh) {
            return LayoutResult::doesNotFit();
        }

        $contentHeight = $cursor;
        $target = $fixed ?? ($breakAt === null ? $stretch : null);
        if ($target !== null) {
            $inner = max(0.0, $target - BoxGeometry::topEdge($style) - BoxGeometry::bottomEdge($style));
            $contentHeight = $fixed !== null ? $inner : max($cursor, $inner);
            $placed = self::distribute($placed, $contentHeight - $cursor, $style, $engine);
        }

        $last = $breakAt === null;
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
        $max = 0.0;
        foreach (self::children($node) as $i => $child) {
            $child = Expander::expand($child);
            if ($child instanceof Spacer || $child instanceof PageBreak) {
                continue;
            }
            $margin = $engine->styleOf($child, $style, $i)->margin->horizontal();
            $size = $engine->intrinsic($child, $style, $i);
            $min = max($min, $size['min'] + $margin);
            $max = max($max, $size['max'] + $margin);
        }
        $edges = $style->padding->horizontal() + $style->borderWidth->horizontal();
        return ['min' => $min + $edges, 'max' => $max + $edges];
    }

    /** @return list<\Papercut\Node|\Papercut\Component> */
    private static function children(Node $node): array
    {
        if ($node instanceof View || $node instanceof Link) {
            return $node->children();
        }
        throw new \LogicException(sprintf('BlockLayouter cannot lay out %s.', $node::class));
    }

    /**
     * True when laying out $node ends with a forced break: its own breakAfter,
     * or (recursively) a trailing PageBreak / breakAfter as its last child.
     */
    private static function forcesBreakAfter(Node $node, ComputedStyle $style, LayoutEngine $engine): bool
    {
        if ($style->breakAfter) {
            return true;
        }
        if (!($node instanceof View || $node instanceof Link) || $style->height instanceof Length) {
            return false;
        }
        $children = $node->children();
        if ($children === [] || $style->direction !== Direction::Column) {
            return false;
        }
        $i = count($children) - 1;
        $last = Expander::expand($children[$i]);
        if ($last instanceof PageBreak) {
            return true;
        }
        return self::forcesBreakAfter($last, $engine->styleOf($last, $style, $i), $engine);
    }

    /**
     * True when $node is a splittable column container whose last child is
     * keepWithNext, or (recursively) itself ends with keepWithNext.
     */
    private static function endsWithKeepWithNext(Node $node, ComputedStyle $style, LayoutEngine $engine): bool
    {
        if (!($node instanceof View || $node instanceof Link) || $style->height instanceof Length || $style->direction !== Direction::Column) {
            return false;
        }
        $children = $node->children();
        if ($children === []) {
            return false;
        }
        $i = count($children) - 1;
        $last = Expander::expand($children[$i]);
        if ($last instanceof PageBreak) {
            return false;
        }
        $ls = $engine->styleOf($last, $style, $i);
        return $ls->keepWithNext || self::endsWithKeepWithNext($last, $ls, $engine);
    }

    /**
     * Re-lays a placed container ending in keepWithNext with its height cut
     * at the top of that trailing chain, so the chain moves on with the next
     * sibling. Null when nothing of the container would remain before it.
     *
     * @param array{node: Node, style: ComputedStyle, index: int, width: float, fresh: bool, token: ?BreakToken, fragment: Fragment, x: float, y: float, height: float, before: float} $p
     * @return ?array{Fragment, BreakToken}
     */
    private static function splitBeforeTrailingKeep(array $p, ComputedStyle $parent, LayoutEngine $engine): ?array
    {
        $cut = self::trailingKeepOffset($p['node'], $p['style'], $p['fragment'], $engine);
        if ($cut === null) {
            return null;
        }
        $res = $engine->layout($p['node'], $parent, new Constraints($p['width'], $cut, $p['fresh']), $p['token'], $p['index']);
        if ($res->status !== Status::Partial || $res->fragment === null || $res->token === null) {
            return null;
        }
        return [$res->fragment, $res->token];
    }

    /**
     * The constraint height at which $node (already laid out as $f) breaks
     * just before its trailing keepWithNext chain; null when the chain starts
     * at its first placed child. Trailing fragment children map one-to-one
     * onto trailing node children: a completed, non-monolithic container
     * places every child after a leading skipped PageBreak.
     */
    private static function trailingKeepOffset(Node $node, ComputedStyle $style, Fragment $f, LayoutEngine $engine): ?float
    {
        if (!($node instanceof View || $node instanceof Link)) {
            return null;
        }
        $children = $node->children();
        $frags = $f->children();
        $n = count($children);
        $m = count($frags);
        $j = $n - 1;
        while ($j >= 0 && $n - $j <= $m) {
            $cs = $engine->styleOf(Expander::expand($children[$j]), $style, $j);
            if (!$cs->keepWithNext) {
                break;
            }
            $j--;
        }
        $bottom = BoxGeometry::bottomEdge($style);
        if ($j < $n - 1) {
            $first = $m - ($n - 1 - $j);
            return $first <= 0 ? null : $frags[$first]['y'] + $bottom;
        }
        if ($m === 0) {
            return null;
        }
        $last = Expander::expand($children[$n - 1]);
        $inner = self::trailingKeepOffset($last, $engine->styleOf($last, $style, $n - 1), $frags[$m - 1]['fragment'], $engine);
        if ($inner === null) {
            // the last child is all chain: cut before it, unless it is all that was placed
            return $m === 1 ? null : $frags[$m - 1]['y'] + $bottom;
        }
        return $frags[$m - 1]['y'] + $inner + $bottom;
    }

    /**
     * A monolithic box never splits: a child's forced-break slices are laid
     * out one after another (fresh, unbounded, no gap) into one wrapper.
     */
    private static function continueInPlace(Fragment $firstSlice, BreakToken $token, Node $child, float $width, int $index, ComputedStyle $style, LayoutEngine $engine): Fragment
    {
        $slices = [$firstSlice];
        for ($guard = 0; $guard < 10_000; $guard++) {
            $res = $engine->layout($child, $style, new Constraints($width, null, true), $token, $index);
            if ($res->fragment === null) {
                break;
            }
            $partial = $res->status === Status::Partial && $res->token !== null;
            if ($partial && $res->token == $token) {
                break; // no progress: the repeated slice is dropped
            }
            $slices[] = $res->fragment;
            if (!$partial) {
                break;
            }
            $token = $res->token;
        }
        $wrapper = new Fragment(max(array_map(static fn (Fragment $f): float => $f->width, $slices)), 0.0);
        $y = 0.0;
        foreach ($slices as $slice) {
            $wrapper->addChild(0.0, $y, $slice);
            $y += $slice->height;
        }
        $wrapper->height = $y;
        return $wrapper;
    }

    private static function alignX(Align $align, float $marginLeft, float $slot, float $width): float
    {
        return $marginLeft + match ($align) {
            Align::Center => ($slot - $width) / 2,
            Align::End => $slot - $width,
            Align::Start, Align::Stretch => 0.0,
        };
    }

    /**
     * Gives free space to grow children (re-laid out at their new definite
     * height), or else shifts children per justify.
     *
     * @param list<array{node: Node, style: ComputedStyle, index: int, width: float, fresh: bool, token: ?BreakToken, fragment: Fragment, x: float, y: float, height: float, before: float}> $placed
     * @return list<array{node: Node, style: ComputedStyle, index: int, width: float, fresh: bool, token: ?BreakToken, fragment: Fragment, x: float, y: float, height: float, before: float}>
     */
    private static function distribute(array $placed, float $free, ComputedStyle $style, LayoutEngine $engine): array
    {
        $n = count($placed);
        if ($free <= self::EPSILON || $n === 0) {
            return $placed;
        }
        $totalGrow = array_sum(array_map(static fn (array $p): float => $p['style']->grow, $placed));
        if ($totalGrow > 0.0) {
            $shift = 0.0;
            foreach ($placed as $k => $p) {
                $p['y'] += $shift;
                if ($p['style']->grow > 0.0) {
                    $extra = $free * $p['style']->grow / $totalGrow;
                    $target = $p['height'] + $extra;
                    $res = $engine->layout($p['node'], $style, new Constraints($p['width'], null, $p['fresh'], $target), null, $p['index']);
                    if ($res->status === Status::Complete && $res->fragment !== null) {
                        $p['fragment'] = $res->fragment;
                    }
                    $p['height'] = $target;
                    $shift += $extra;
                }
                $placed[$k] = $p;
            }
            return $placed;
        }

        [$lead, $between] = BoxGeometry::justify($style->justify, $free, $n);
        foreach ($placed as $k => $p) {
            $placed[$k]['y'] += $lead + $between * $k;
        }
        return $placed;
    }
}
