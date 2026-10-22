<?php

declare(strict_types=1);

namespace Papercut\Layout;

/**
 * Side-by-side children (a flex line's items, a table row's cells) that
 * fragment together. measure() lays them out unbounded to find the shared
 * height; split() lays them out in parallel within a height, each child
 * keeping its own break token (null = finished; on resume finished children
 * draw nothing).
 */
final class ParallelSplit
{
    private function __construct()
    {
    }

    /**
     * Lays every child out unbounded and returns the fragments with the
     * tallest outer extent (never below $min).
     *
     * @template K of int
     * @param list<K> $keys
     * @param \Closure(K): Fragment $layout
     * @param \Closure(K, Fragment): float $extent outer height of a child's fragment (adds margins)
     * @return array{height: float, fragments: array<K, Fragment>}
     */
    public static function measure(array $keys, float $min, \Closure $layout, \Closure $extent): array
    {
        $fragments = [];
        $height = $min;
        foreach ($keys as $k) {
            $fragments[$k] = $layout($k);
            $height = max($height, $extent($k, $fragments[$k]));
        }
        return ['height' => $height, 'fragments' => $fragments];
    }

    /**
     * Parallel split in $height. $tokens null = fresh split (all children
     * start, each offset by its leading space); otherwise only children with
     * a non-null token continue. A finished child adds its trailing space,
     * truncated at $height. Returns null when a child cannot place anything.
     *
     * @param array<int, array{x: float, top: float, bottom: float}> $slots per child: x, leading space (first slice only), trailing space
     * @param ?array<int, ?BreakToken> $tokens
     * @param \Closure(int, ?float, ?BreakToken): LayoutResult $layout lays child j out in a height
     * @return ?array{height: float, tokens: array<int, ?BreakToken>, cells: list<array{x: float, y: float, fragment: Fragment}>}
     */
    public static function split(array $slots, ?float $height, ?array $tokens, \Closure $layout): ?array
    {
        $next = [];
        $cells = [];
        $total = 0.0;
        foreach ($slots as $j => $slot) {
            $next[$j] = null;
            $resume = $tokens[$j] ?? null;
            if ($tokens !== null && $resume === null) {
                continue;
            }
            $top = $tokens === null ? $slot['top'] : 0.0;
            $res = $layout($j, $height === null ? null : max(0.0, $height - $top), $resume);
            if ($res->status === Status::DoesNotFit || $res->fragment === null) {
                return null;
            }
            $done = $res->status !== Status::Partial || $res->token === null;
            $next[$j] = $done ? null : $res->token;
            $cells[] = ['x' => $slot['x'], 'y' => $top, 'fragment' => $res->fragment];
            $bottom = $top + $res->fragment->height;
            if ($done) {
                // Trailing space is truncated at the page end, never pushing the slice past $height.
                $bottom = $height === null ? $bottom + $slot['bottom'] : max($bottom, min($bottom + $slot['bottom'], $height));
            }
            $total = max($total, $bottom);
        }
        return ['height' => $total, 'tokens' => $next, 'cells' => $cells];
    }

    /**
     * True while any child still has content left.
     *
     * @param array<int, ?BreakToken> $tokens
     */
    public static function pending(array $tokens): bool
    {
        foreach ($tokens as $t) {
            if ($t !== null) {
                return true;
            }
        }
        return false;
    }
}
