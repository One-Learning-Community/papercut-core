<?php

declare(strict_types=1);

namespace Papercut\Tests\Style;

use Papercut\Decorator\DefaultStyles;
use Papercut\Exception\InvalidValue;
use Papercut\Text;
use Papercut\Style\{Style, StyleResolver, Theme, ThemeOverride};
use Papercut\Value\{Color, Length};
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

final class ThemeOverrideTest extends TestCase
{
    public function test_values_merge_and_absent_ones_stay(): void
    {
        $t = Theme::default()->withOverride(new ThemeOverride(fontSize: pt(11), color: Color::parse('red-500')));
        self::assertSame(11.0, $t->fontSize);
        self::assertSame('Inter', $t->fontFamily);
        self::assertSame(1.4, $t->lineHeight);
    }

    public function test_an_empty_override_returns_the_same_theme(): void
    {
        $base = Theme::default();
        self::assertSame($base, $base->withOverride(new ThemeOverride()));
    }

    public function test_a_named_style_merges_onto_the_built_in_default(): void
    {
        $t = Theme::default()->withOverride(new ThemeOverride(styles: ['callout.warning' => Style::make(background: Color::parse('red-50'))]));
        $s = $t->named('callout.warning');
        self::assertEquals(Color::parse('red-50'), $s->background);
        self::assertEquals(DefaultStyles::get('callout.warning')?->borderColor, $s->borderColor);
    }

    public function test_later_overrides_win_and_styles_merge_per_name(): void
    {
        $o = (new ThemeOverride(fontSize: pt(11), styles: ['card' => Style::make(padding: \Papercut\Value\Edges::all(pt(4)))]))
            ->merge(new ThemeOverride(fontSize: pt(12), styles: ['card' => Style::make(background: Color::parse('slate-50'))]));
        $theme = Theme::default()->withOverride($o);
        $card = $theme->named('card');
        self::assertSame(12.0, $theme->fontSize);
        self::assertEquals(Color::parse('slate-50'), $card->background);
        self::assertSame(4.0, $card->padding?->top->points);
    }

    public function test_font_size_must_be_absolute(): void
    {
        $this->expectException(InvalidValue::class);
        new ThemeOverride(fontSize: Length::em(2));
    }

    public function test_h1_style_restyles_level_1_headings_only(): void
    {
        $theme = Theme::default()->withStyle('h1', Style::make(fontSize: pt(36), color: Color::parse('red-500')));
        $r = new StyleResolver($theme);
        self::assertSame(36.0, $r->resolve(Text::h1('Title'), $r->root())->fontSize);
        self::assertSame(18.0, $r->resolve(Text::h2('Sub'), $r->root())->fontSize);
        self::assertSame(30.0, $r->resolve(Text::h1('T')->size(pt(30)), $r->root())->fontSize);
    }

    public function test_without_h_styles_headings_resolve_as_before(): void
    {
        $r = new StyleResolver(Theme::default());
        self::assertSame(24.0, $r->resolve(Text::h1('T'), $r->root())->fontSize);
    }
}
