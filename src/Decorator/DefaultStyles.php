<?php

declare(strict_types=1);

namespace Papercut\Decorator;

use Papercut\Style\{Align, Direction, Justify, Style, WhiteSpace};
use Papercut\Value\{Color, Edges, Length};

use function Papercut\pt;

/**
 * Built-in styles for the decorator components. A Theme consults its own
 * styles first, then this map.
 */
final class DefaultStyles
{
    /** @var array<string, Style>|null */
    private static ?array $styles = null;

    public static function get(string $name): ?Style
    {
        return self::all()[$name] ?? null;
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_map(strval(...), array_keys(self::all()));
    }

    /** @return array<string, Style> */
    private static function all(): array
    {
        return self::$styles ??= self::build();
    }

    private static function c(string $name): Color
    {
        return Color::named($name);
    }

    private static function sides(float $top, float $right, float $bottom, float $left): Edges
    {
        return Edges::of(pt($top), pt($right), pt($bottom), pt($left));
    }

    /** @return array<string, Style> */
    private static function build(): array
    {
        $s = [];
        $zero = Length::zero();

        $s['card'] = Style::make(borderWidth: Edges::all(pt(1)), borderColor: self::c('slate-300'), radius: pt(4), gap: $zero);
        $s['card.title'] = Style::make(
            padding: Edges::of(pt(6), pt(10)),
            // The card radius less its 1pt border, so the strip follows the inside of the corner.
            radius: pt(3),
            background: self::c('slate-100'),
            borderWidth: Edges::of($zero, $zero, pt(1), $zero),
            borderColor: self::c('slate-300'),
            fontWeight: 700,
        );
        $s['card.body'] = Style::make(padding: Edges::all(pt(10)), gap: pt(6));

        $s['callout'] = Style::make(
            padding: Edges::of(pt(8), pt(10)),
            borderWidth: Edges::of($zero, $zero, $zero, pt(3)),
            radius: pt(2),
            gap: pt(4),
        );
        $s['callout.title'] = Style::make(fontWeight: 700);
        foreach (['note' => 'sky', 'tip' => 'emerald', 'warning' => 'amber', 'danger' => 'red'] as $tone => $hue) {
            $s['callout.' . $tone] = Style::make(borderColor: self::c($hue . '-500'), background: self::c($hue . '-50'));
        }
        foreach (['note' => 'sky', 'tip' => 'emerald', 'warning' => 'amber', 'danger' => 'red'] as $tone => $hue) {
            $s['callout.title.' . $tone] = Style::make(color: self::c($hue . '-800'));
        }

        $s['blockquote'] = Style::make(
            padding: self::sides(2, 0, 2, 12),
            borderWidth: Edges::of($zero, $zero, $zero, pt(3)),
            borderColor: self::c('slate-300'),
            color: self::c('slate-700'),
            italic: true,
            gap: pt(4),
        );
        $s['blockquote.cite'] = Style::make(italic: false, fontSize: Length::em(0.9), color: self::c('slate-500'));

        $s['markup.table'] = Style::make(borderWidth: Edges::of(pt(0.75), $zero, $zero, pt(0.75)), borderColor: self::c('slate-300'));
        $s['markup.td'] = Style::make(borderWidth: Edges::of($zero, pt(0.75), pt(0.75), $zero), borderColor: self::c('slate-300'));
        $s['markup.th'] = Style::make(borderWidth: Edges::of($zero, pt(0.75), pt(0.75), $zero), borderColor: self::c('slate-300'), background: self::c('slate-50'));

        $s['markup.hr'] = Style::make(borderWidth: Edges::of($zero, $zero, pt(0.75), $zero), borderColor: self::c('slate-300'), margin: self::sides(6, 0, 6, 0));

        $s['section'] = Style::make(gap: pt(6));
        // The heading's row direction is fixed in Section; the entry is kept per the spec, but direction here has no effect.
        $s['section.heading'] = Style::make(direction: Direction::Row, gap: pt(4));
        $s['section.body'] = Style::make(gap: pt(6));
        $s['section.title'] = Style::make(fontWeight: 700);
        $s['section.number'] = Style::make(fontWeight: 700, color: self::c('slate-500'), minWidth: pt(18));
        $s['section.rule'] = Style::make(
            borderWidth: Edges::of($zero, $zero, pt(0.75), $zero),
            borderColor: self::c('slate-200'),
            padding: Edges::of($zero, $zero, pt(3), $zero),
        );

        $s['field'] = Style::make(
            borderWidth: Edges::all(pt(1)),
            borderColor: self::c('slate-300'),
            radius: pt(3),
            padding: Edges::of(pt(5), pt(7)),
        );
        $s['field.label'] = Style::make(fontSize: Length::em(0.9), color: self::c('slate-600'));
        $s['field.placeholder'] = Style::make(italic: true, color: self::c('slate-400'));

        $s['choice'] = Style::make(gap: pt(4));
        $s['choice.option'] = Style::make(gap: pt(6));
        // Sized from the text (0.75em: 9pt at 12pt); Choice centres it in a one-line slot.
        $s['choice.marker'] = Style::make(
            width: Length::em(0.75),
            height: Length::em(0.75),
            borderWidth: Edges::all(pt(1)),
            borderColor: self::c('slate-400'),
            radius: Length::em(0.125),
            alignItems: Align::Center,
            justify: Justify::Center,
            shrink: 0.0,
        );
        $s['choice.marker.radio'] = Style::make(radius: Length::em(0.375));
        $s['choice.marker.selected'] = Style::make(borderColor: self::c('slate-800'));
        $s['choice.mark'] = Style::make(width: Length::em(0.42), height: Length::em(0.42), background: self::c('slate-800'), radius: Length::em(0.08));
        $s['choice.mark.radio'] = Style::make(radius: Length::em(0.21));
        $s['choice.label.selected'] = Style::make(fontWeight: 700);

        $s['key-value'] = Style::make();
        $s['key-value.label'] = Style::make(padding: self::sides(2, 12, 2, 0), color: self::c('slate-600'));
        $s['key-value.value'] = Style::make(padding: Edges::of(pt(2), $zero));

        $s['badge'] = Style::make(alignSelf: Align::Start, padding: Edges::of(pt(1.5), pt(6)), radius: pt(8), fontSize: Length::em(0.9), fontWeight: 700);
        foreach (['neutral' => 'slate', 'note' => 'sky', 'tip' => 'emerald', 'warning' => 'amber', 'danger' => 'red'] as $tone => $hue) {
            $s['badge.' . $tone] = Style::make(background: self::c($hue . '-100'), color: self::c($hue . '-800'));
        }

        // Preformatted text: the theme's mono family at 0.9em, spaces and line breaks kept.
        $s['pre'] = Style::make(
            fontFamily: 'monospace',
            fontSize: Length::em(0.9),
            whiteSpace: WhiteSpace::PreWrap,
            background: self::c('slate-100'),
            padding: self::sides(6, 8, 6, 8),
            margin: self::sides(0, 0, 6, 0),
        );
        $s['code'] = Style::make(fontFamily: 'monospace', fontSize: Length::em(0.9), background: self::c('slate-100'));

        return $s;
    }
}
