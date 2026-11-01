<?php

declare(strict_types=1);

namespace Papercut\Tests\Decorator;

use Papercut\{Text, View};
use Papercut\Decorator\{Decorator, Stat};
use Papercut\Style\{ComputedStyle, StyleResolver, Theme};
use Papercut\Value\Color;
use PHPUnit\Framework\TestCase;

final class StatTest extends TestCase
{
    public function test_is_a_decorator(): void
    {
        self::assertInstanceOf(Decorator::class, Stat::make('1'));
    }

    public function test_value_only(): void
    {
        $outer = Stat::make('$48,200')->render();

        self::assertSame('stat', $outer->styleName());
        self::assertCount(1, $outer->children());
        $value = self::node($outer, 0);
        self::assertInstanceOf(Text::class, $value);
        self::assertSame('stat.value', $value->styleName());
    }

    public function test_label_value_note_in_order(): void
    {
        $outer = Stat::make('$48,200')->label('Revenue')->note('Up 12%')->render();

        $names = array_map(fn ($i) => self::node($outer, $i)->styleName(), array_keys($outer->children()));
        self::assertSame(['stat.label', 'stat.value', 'stat.note'], $names);
    }

    public function test_component_label_and_value(): void
    {
        $label = Text::make('Rich');
        $value = View::make(Text::make('42'));
        $outer = Stat::make($value)->label($label)->render();

        $l = self::node($outer, 0);
        $v = self::node($outer, 1);
        self::assertInstanceOf(View::class, $l);
        self::assertInstanceOf(View::class, $v);
        self::assertSame($label, $l->children()[0]);
        self::assertSame('stat.value', $v->styleName());
        self::assertSame($value, $v->children()[0]);
    }

    public function test_blank_label_and_note_are_dropped(): void
    {
        self::assertCount(1, Stat::make('1')->label('  ')->note('')->render()->children());
    }

    public function test_style_and_variants(): void
    {
        $outer = Stat::make('1')->label('L')->variant('big')->apply('mine')->render();

        self::assertSame('stat stat.big? mine', $outer->styleName());
        self::assertSame('stat.label stat.label.big?', self::node($outer, 0)->styleName());
        self::assertSame(['stat', 'stat.label', 'stat.value', 'stat.note'], Stat::make('1')->partNames());
    }

    public function test_default_look(): void
    {
        $theme = Theme::default();
        $resolver = new StyleResolver($theme);
        $outer = Stat::make('9')->label('L')->render();
        $panel = $resolver->resolve($outer, ComputedStyle::root($theme));
        self::assertEquals(Color::named('slate-50'), $panel->background);
        $value = $resolver->resolve(self::node($outer, 1), $panel);
        self::assertSame(700, $value->fontWeight);
        self::assertGreaterThan($panel->fontSize, $value->fontSize);
    }

    private static function node(\Papercut\View $parent, int $i): \Papercut\Text|\Papercut\View
    {
        $child = $parent->children()[$i] ?? null;
        self::assertTrue($child instanceof \Papercut\Text || $child instanceof \Papercut\View);
        return $child;
    }
}
