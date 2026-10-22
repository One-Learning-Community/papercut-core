<?php

declare(strict_types=1);

namespace Papercut\Layout;

use Papercut\Paint\{Line, Rect};
use Papercut\Style\{BorderStyle, ComputedStyle, Justify};
use Papercut\Value\{Auto, Length, Percent};

/**
 * Box-model helpers. Widths and heights are border-box sizes. Margins are
 * applied by the parent when it positions a child, so $available here is the
 * space left after the parent removed the child's horizontal margins.
 */
final class BoxGeometry
{
    private function __construct()
    {
    }

    /** Border-box width: width resolved against $available, clamped to min/max. */
    public static function borderBoxWidth(ComputedStyle $s, float $available): float
    {
        $w = self::resolve($s->width, $available) ?? $available;
        $max = self::resolve($s->maxWidth, $available);
        if ($max !== null) {
            $w = min($w, $max);
        }
        $min = self::resolve($s->minWidth, $available);
        if ($min !== null) {
            $w = max($w, $min);
        }
        return max(0.0, $w);
    }

    public static function contentWidth(ComputedStyle $s, float $available): float
    {
        return max(0.0, self::borderBoxWidth($s, $available) - $s->padding->horizontal() - $s->borderWidth->horizontal());
    }

    /** Top padding + border (only on the first fragment). */
    public static function topEdge(ComputedStyle $s): float
    {
        return $s->padding->top->points + $s->borderWidth->top->points;
    }

    /** Bottom padding + border (only on the last fragment). */
    public static function bottomEdge(ComputedStyle $s): float
    {
        return $s->padding->bottom->points + $s->borderWidth->bottom->points;
    }

    public static function outerHeight(ComputedStyle $s, float $contentHeight, bool $first, bool $last): float
    {
        return ($first ? self::topEdge($s) : 0.0) + $contentHeight + ($last ? self::bottomEdge($s) : 0.0);
    }

    /** Adds background, borders and opacity for one slice of a box. */
    public static function decorate(Fragment $f, ComputedStyle $s, bool $first, bool $last): void
    {
        $w = $f->width;
        $h = $f->height;
        if ($s->background !== null) {
            $f->addOp(new Rect(0.0, 0.0, $w, $h, fill: $s->background, radius: $s->radius));
        }

        $b = $s->borderWidth;
        $top = $b->top->points;
        $right = $b->right->points;
        $bottom = $b->bottom->points;
        $left = $b->left->points;
        $uniform = $top > 0.0 && $top === $right && $top === $bottom && $top === $left;
        if ($uniform && $first && $last && $s->borderStyle === BorderStyle::Solid) {
            $f->addOp(new Rect(
                $top / 2,
                $top / 2,
                max(0.0, $w - $top),
                max(0.0, $h - $top),
                stroke: $s->borderColor,
                strokeWidth: $top,
                radius: max(0.0, $s->radius - $top / 2),
            ));
        } else {
            $dashed = $s->borderStyle === BorderStyle::Dashed;
            $color = $s->borderColor;
            if ($first && $top > 0.0) {
                $f->addOp(new Line(0.0, $top / 2, $w, $top / 2, $color, $top, $dashed));
            }
            if ($right > 0.0) {
                $f->addOp(new Line($w - $right / 2, 0.0, $w - $right / 2, $h, $color, $right, $dashed));
            }
            if ($last && $bottom > 0.0) {
                $f->addOp(new Line(0.0, $h - $bottom / 2, $w, $h - $bottom / 2, $color, $bottom, $dashed));
            }
            if ($left > 0.0) {
                $f->addOp(new Line($left / 2, 0.0, $left / 2, $h, $color, $left, $dashed));
            }
        }

        if ($s->opacity < 1.0) {
            $f->opacity = $s->opacity;
        }
    }

    /** Resolves a Length/Percent against $basis; Auto, null or a Percent without a basis → null. */
    public static function resolve(Length|Percent|Auto|null $v, ?float $basis): ?float
    {
        return match (true) {
            $v instanceof Length => $v->points,
            $v instanceof Percent => $basis === null ? null : $v->resolve($basis),
            default => null,
        };
    }

    /** Border-box height from an explicit Length, or a Percent of definiteHeight, clamped to min/max. Monolithic. */
    public static function explicitHeight(ComputedStyle $s, Constraints $c): ?float
    {
        return self::clampHeight($s, $c, self::resolve($s->height, $c->definiteHeight));
    }

    /** Border-box height the box should have: the explicit height, else a stretched definiteHeight (a minimum fill). */
    public static function definiteHeight(ComputedStyle $s, Constraints $c): ?float
    {
        return self::clampHeight($s, $c, self::resolve($s->height, $c->definiteHeight) ?? $c->definiteHeight);
    }

    /**
     * The border-box min-height of a content-sized box, fitted to the space: null without a
     * min-height; DoesNotFit (false) when it is taller than the space left on a page that
     * already has content; capped to the space on a fresh page.
     */
    public static function minimumFill(ComputedStyle $s, Constraints $c): float|false|null
    {
        $min = self::resolve($s->minHeight, $c->definiteHeight);
        if ($min === null || $min <= 0.0) {
            return null;
        }
        // As in CSS, min-height wins over a smaller max-height.
        if ($c->height !== null && $min > $c->height + 1e-9) {
            return $c->fresh ? $c->height : false;
        }
        return $min;
    }

    /**
     * Whether a content-sized box is kept whole and capped by its max-height: when the max-height
     * fits the space, or the page already has content (the box then moves on). Only a max-height
     * taller than an empty page lets the box split, so nothing is ever cut off.
     */
    public static function capsWhole(ComputedStyle $s, Constraints $c): bool
    {
        $max = self::resolve($s->maxHeight, $c->definiteHeight);
        if ($max === null) {
            return false;
        }
        // min-height wins over a smaller max-height, so the box can be as tall as the larger of the two.
        $cap = max($max, self::resolve($s->minHeight, $c->definiteHeight) ?? 0.0);
        return $c->height === null || $cap <= $c->height + 1e-9 || !$c->fresh;
    }

    /**
     * The content height of an unsplittable box placed in the space left: unchanged when it fits, null
     * (DoesNotFit) when it does not and the page already has content, cut to the space on a fresh page.
     */
    public static function fitContent(ComputedStyle $s, Constraints $c, float $contentHeight): ?float
    {
        $edges = self::topEdge($s) + self::bottomEdge($s);
        if ($c->height === null || $contentHeight + $edges <= $c->height + 1e-9) {
            return $contentHeight;
        }
        return $c->fresh ? max(0.0, $c->height - $edges) : null;
    }

    /** Caps the content height of a content-sized, single-slice box so its border box fits max-height. */
    public static function capContent(ComputedStyle $s, Constraints $c, float $contentHeight): float
    {
        $max = self::resolve($s->maxHeight, $c->definiteHeight);
        if ($max === null) {
            return $contentHeight;
        }
        $edges = self::topEdge($s) + self::bottomEdge($s);
        $capped = min($contentHeight, $max - $edges);
        $min = self::resolve($s->minHeight, $c->definiteHeight);
        // min-height wins over max-height, as in CSS.
        return max(0.0, $min === null ? $capped : max($capped, min($contentHeight, $min - $edges)));
    }

    /** Clamps a border-box height to min/max-height (percentages of definiteHeight); null stays null. */
    public static function clampHeight(ComputedStyle $s, Constraints $c, ?float $h): ?float
    {
        if ($h === null) {
            return null;
        }
        $max = self::resolve($s->maxHeight, $c->definiteHeight);
        if ($max !== null) {
            $h = min($h, $max);
        }
        $min = self::resolve($s->minHeight, $c->definiteHeight);
        if ($min !== null) {
            $h = max($h, $min);
        }
        return max(0.0, $h);
    }

    /**
     * Distributes $free space over $n items per justify: [lead, between],
     * the offset before the first item and the extra space between items.
     * No free space (or no items) gives [0, 0].
     *
     * @return array{float, float}
     */
    public static function justify(Justify $justify, float $free, int $n): array
    {
        if ($free <= 1e-9 || $n <= 0) {
            return [0.0, 0.0];
        }
        return match ($justify) {
            Justify::Start => [0.0, 0.0],
            Justify::End => [$free, 0.0],
            Justify::Center => [$free / 2, 0.0],
            Justify::SpaceBetween => $n > 1 ? [0.0, $free / ($n - 1)] : [0.0, 0.0],
            Justify::SpaceAround => [$free / $n / 2, $free / $n],
            Justify::SpaceEvenly => [$free / ($n + 1), $free / ($n + 1)],
        };
    }
}
