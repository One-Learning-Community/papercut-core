<?php

declare(strict_types=1);

namespace Papercut\Layout;

use Papercut\Style\ComputedStyle;

/**
 * Sizing for replaced content with an intrinsic size and aspect ratio: images and SVG.
 */
final class ReplacedBox
{
    private function __construct()
    {
    }

    /**
     * The border-box size for content of intrinsic size $iw x $ih points, or null when it
     * does not fit the height left. Like the width limit, an empty page's height shrinks
     * the box, unless its height was set explicitly.
     *
     * @return array{float, float}|null
     */
    public static function size(ComputedStyle $style, Constraints $c, float $iw, float $ih): ?array
    {
        $edgesH = $style->padding->horizontal() + $style->borderWidth->horizontal();
        $edgesV = $style->padding->vertical() + $style->borderWidth->vertical();

        [$w, $h] = self::fit($style, $c, $iw, $ih);

        if ($c->height !== null && $h > $c->height + 1e-9) {
            $explicit = BoxGeometry::resolve($style->height, $c->definiteHeight) !== null;
            if (!$c->fresh || $explicit || $c->height <= $edgesV) {
                return null;
            }
            $w = $edgesH + max(0.0, $w - $edgesH) * ($c->height - $edgesV) / ($h - $edgesV);
            $h = $c->height;
        }

        return [$w, $h];
    }

    /**
     * Border-box size: explicit width/height (the other one derived from the
     * content's aspect ratio), else the intrinsic size; then min/max clamps,
     * then scaled down to fit c.width. Aspect is kept unless both were set,
     * or a min clamp on one axis would break a max on the other (max wins).
     *
     * @return array{float, float}
     */
    private static function fit(ComputedStyle $style, Constraints $c, float $iw, float $ih): array
    {
        $edgesH = $style->padding->horizontal() + $style->borderWidth->horizontal();
        $edgesV = $style->padding->vertical() + $style->borderWidth->vertical();
        $toH = static fn (float $w): float => $iw > 0.0 ? max(0.0, $w - $edgesH) * $ih / $iw + $edgesV : $edgesV;
        $toW = static fn (float $h): float => $ih > 0.0 ? max(0.0, $h - $edgesV) * $iw / $ih + $edgesH : $edgesH;

        $w = BoxGeometry::resolve($style->width, $c->width);
        $h = BoxGeometry::resolve($style->height, $c->definiteHeight);
        $keep = $w === null || $h === null;
        if ($w === null && $h === null) {
            $w = $iw + $edgesH;
            $h = $toH($w);
        } elseif ($h === null) {
            $h = $toH((float) $w);
        } elseif ($w === null) {
            $w = $toW($h);
        }

        $clamped = self::clamp($w, BoxGeometry::resolve($style->minWidth, $c->width), BoxGeometry::resolve($style->maxWidth, $c->width));
        if ($clamped !== $w) {
            $h = $keep ? $toH($clamped) : $h;
            $w = $clamped;
        }
        $clamped = self::clamp($h, BoxGeometry::resolve($style->minHeight, $c->definiteHeight), BoxGeometry::resolve($style->maxHeight, $c->definiteHeight));
        if ($clamped !== $h) {
            $w = $keep ? $toW($clamped) : $w;
            $h = $clamped;
        }

        // A min clamp on one axis can push the other past its max: the max wins, at the cost of the aspect ratio.
        $maxW = BoxGeometry::resolve($style->maxWidth, $c->width);
        $maxH = BoxGeometry::resolve($style->maxHeight, $c->definiteHeight);
        $w = $maxW === null ? $w : min($w, $maxW);
        $h = $maxH === null ? $h : min($h, $maxH);

        $limit = max(0.0, $c->width);
        if ($w > $limit + 1e-9) {
            $h = $keep ? $toH($limit) : ($w > 0.0 ? $h * $limit / $w : $h);
            $w = $limit;
        }
        return [max(0.0, $w), max(0.0, $h)];
    }

    private static function clamp(float $v, ?float $min, ?float $max): float
    {
        if ($max !== null) {
            $v = min($v, $max);
        }
        if ($min !== null) {
            $v = max($v, $min);
        }
        return $v;
    }
}
