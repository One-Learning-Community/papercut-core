<?php

declare(strict_types=1);

namespace Papercut\Html;

use Papercut\Style\{Align, ComputedStyle, Justify, Theme, WhiteSpace};
use Papercut\Text\{FontClass, FontSet};
use Papercut\Value\{Auto, Color, Edges, Length, Percent};

/** Pure mapping from resolved style values to CSS declarations. Only values that differ from the root defaults are emitted. */
final class Css
{
    public static function num(float $v): string
    {
        $s = rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.');
        return $s === '' || $s === '-0' ? '0' : $s;
    }

    public static function pt(float $v): string
    {
        return self::num($v) . 'pt';
    }

    public static function length(Length|Percent|Auto $v): ?string
    {
        return match (true) {
            $v instanceof Length => self::pt($v->points),
            $v instanceof Percent => self::num($v->value) . '%',
            default => null,
        };
    }

    public static function color(Color $c): string
    {
        if ($c->isOpaque()) {
            return sprintf('#%02x%02x%02x', $c->r, $c->g, $c->b);
        }
        return sprintf('rgba(%d, %d, %d, %s)', $c->r, $c->g, $c->b, self::num($c->alpha));
    }

    /** A CSS string literal. `<` is escaped too, so `</style>` can never appear inside a style block. */
    public static function string(string $s): string
    {
        $out = preg_replace_callback(
            '/[\\\\"<\x00-\x1f\x7f]/',
            static fn (array $m): string => match ($m[0]) {
                '\\' => '\\\\',
                '"' => '\\"',
                default => '\\' . dechex(ord($m[0])) . ' ',
            },
            $s,
        );
        return '"' . ($out ?? '') . '"';
    }

    /** @param list<string> $fallbacks families tried before the generic ones */
    public static function fontFamily(string $family, array $fallbacks = [], ?FontClass $class = null): string
    {
        return implode(', ', [...array_map(self::string(...), [$family, ...$fallbacks]), $class?->cssGeneric() ?? 'system-ui, sans-serif']);
    }

    /** @return array<string,string> */
    public static function box(ComputedStyle $s): array
    {
        $d = [];
        foreach (['width' => $s->width, 'height' => $s->height, 'min-width' => $s->minWidth, 'max-width' => $s->maxWidth, 'min-height' => $s->minHeight, 'max-height' => $s->maxHeight] as $k => $v) {
            $css = $v === null ? null : self::length($v);
            if ($css !== null) {
                $d[$k] = $css;
            }
        }
        if (!self::zero($s->padding)) {
            $d['padding'] = self::four($s->padding);
        }
        if (!self::zero($s->margin)) {
            $d['margin'] = self::four($s->margin);
        }
        if (!self::zero($s->borderWidth)) {
            $d['border-width'] = self::four($s->borderWidth);
            $d['border-style'] = $s->borderStyle->value;
            $d['border-color'] = self::color($s->borderColor);
        }
        if ($s->radius !== 0.0) {
            $d['border-radius'] = self::pt($s->radius);
        }
        if ($s->background !== null) {
            $d['background'] = self::color($s->background);
        }
        if ($s->opacity !== 1.0) {
            $d['opacity'] = self::num($s->opacity);
        }
        return $d;
    }

    /**
     * @param list<string> $fallbacks
     * @return array<string,string> every property when $parent is null, otherwise those that differ
     */
    public static function text(ComputedStyle $s, ?ComputedStyle $parent, array $fallbacks = [], ?FontSet $fonts = null): array
    {
        $d = [];
        if ($parent === null || $s->fontFamily !== $parent->fontFamily) {
            $d['font-family'] = self::fontFamily($s->fontFamily, $fallbacks, $fonts?->info($s->fontFamily)?->class);
        }
        if ($parent === null || $s->fontSize !== $parent->fontSize) {
            $d['font-size'] = self::pt($s->fontSize);
        }
        if ($parent === null || $s->fontWeight !== $parent->fontWeight) {
            $d['font-weight'] = (string) $s->fontWeight;
        }
        if ($parent === null || $s->italic !== $parent->italic) {
            $d['font-style'] = $s->italic ? 'italic' : 'normal';
        }
        if ($parent === null || self::color($s->color) !== self::color($parent->color)) {
            $d['color'] = self::color($s->color);
        }
        if ($parent === null || $s->lineHeight !== $parent->lineHeight) {
            $d['line-height'] = self::num($s->lineHeight);
        }
        if ($parent === null || $s->letterSpacing !== $parent->letterSpacing) {
            $d['letter-spacing'] = self::pt($s->letterSpacing);
        }
        if ($parent === null || $s->textAlign !== $parent->textAlign) {
            $d['text-align'] = $s->textAlign->value;
        }
        if ($parent === null || $s->underline !== $parent->underline || $s->strike !== $parent->strike) {
            $d['text-decoration'] = match (true) {
                $s->underline && $s->strike => 'underline line-through',
                $s->underline => 'underline',
                $s->strike => 'line-through',
                default => 'none',
            };
        }
        if ($parent === null ? $s->whiteSpace !== WhiteSpace::Normal : $s->whiteSpace !== $parent->whiteSpace) {
            $d['white-space'] = $s->whiteSpace->value;
            if ($s->whiteSpace === WhiteSpace::PreWrap) {
                // Tabs advance like the PDF's (4 columns), not the browser's 8.
                $d['tab-size'] = '4';
            }
        }

        return $d;
    }

    /** @return array<string,string> */
    public static function flexContainer(ComputedStyle $s): array
    {
        $d = ['display' => 'flex', 'flex-direction' => $s->direction->value];
        if ($s->gap !== 0.0) {
            $d['gap'] = self::pt($s->gap);
        }
        if ($s->justify !== Justify::Start) {
            $d['justify-content'] = self::justify($s->justify);
        }
        if ($s->alignItems !== Align::Stretch) {
            $d['align-items'] = self::align($s->alignItems);
        }
        if ($s->wrap) {
            $d['flex-wrap'] = 'wrap';
        }
        return $d;
    }

    /** @return array<string,string> */
    public static function flexItem(ComputedStyle $s): array
    {
        $d = [];
        if ($s->grow !== 0.0 || $s->shrink !== 1.0 || !$s->basis instanceof Auto) {
            $d['flex'] = self::num($s->grow) . ' ' . self::num($s->shrink) . ' ' . (self::length($s->basis) ?? 'auto');
        }
        if ($s->alignSelf !== null) {
            $d['align-self'] = self::align($s->alignSelf);
        }
        return $d;
    }

    /** @return array<string,string> */
    public static function pagination(ComputedStyle $s, ?ComputedStyle $parent = null): array
    {
        $d = [];
        if ($s->breakBefore) {
            $d['break-before'] = 'page';
        }
        if ($s->breakAfter) {
            $d['break-after'] = 'page';
        }
        if ($s->keepTogether) {
            $d['break-inside'] = 'avoid';
        }
        if ($s->keepWithNext && !$s->breakAfter) {
            $d['break-after'] = 'avoid';
        }
        if ($s->minLines !== ($parent === null ? self::rootMinLines() : $parent->minLines)) {
            $d['orphans'] = (string) $s->minLines;
            $d['widows'] = (string) $s->minLines;
        }
        return $d;
    }

    private static function rootMinLines(): int
    {
        static $v = null;
        return $v ??= ComputedStyle::root(Theme::default())->minLines;
    }

    /** @param array<string,string> $decls */
    public static function attr(array $decls): string
    {
        $parts = [];
        foreach ($decls as $k => $v) {
            $parts[] = $k . ':' . $v;
        }
        return implode(';', $parts);
    }

    /** Horizontal padding, border and margin of a box, in points. */
    public static function horizontalEdges(ComputedStyle $s): float
    {
        return $s->padding->left->points + $s->padding->right->points
            + $s->borderWidth->left->points + $s->borderWidth->right->points
            + $s->margin->left->points + $s->margin->right->points;
    }

    private static function zero(Edges $e): bool
    {
        return $e->top->points === 0.0 && $e->right->points === 0.0 && $e->bottom->points === 0.0 && $e->left->points === 0.0;
    }

    private static function four(Edges $e): string
    {
        return self::pt($e->top->points) . ' ' . self::pt($e->right->points) . ' ' . self::pt($e->bottom->points) . ' ' . self::pt($e->left->points);
    }

    private static function justify(Justify $j): string
    {
        return match ($j) {
            Justify::Start => 'flex-start',
            Justify::End => 'flex-end',
            Justify::Center => 'center',
            Justify::SpaceBetween => 'space-between',
            Justify::SpaceAround => 'space-around',
            Justify::SpaceEvenly => 'space-evenly',
        };
    }

    private static function align(Align $a): string
    {
        return match ($a) {
            Align::Start => 'flex-start',
            Align::End => 'flex-end',
            Align::Center => 'center',
            Align::Stretch => 'stretch',
        };
    }
}
