<?php

declare(strict_types=1);

namespace Papercut\Tests\Decorator;

use Papercut\Decorator\{Badge, Card, Field, Section};
use Papercut\Style\{ComputedStyle, StyleResolver, Theme};
use Papercut\Value\Color;
use PHPUnit\Framework\TestCase;

/** The variants that ship with the default theme resolve through the normal variant names. */
final class BuiltInVariantsTest extends TestCase
{
    private function resolve(\Papercut\Node $node): ComputedStyle
    {
        $theme = Theme::default();
        return (new StyleResolver($theme))->resolve($node, ComputedStyle::root($theme));
    }

    public function test_field_line_keeps_only_the_bottom_rule(): void
    {
        $c = $this->resolve(Field::make('x')->variant('line')->render());
        self::assertSame(0.0, $c->borderWidth->top->points);
        self::assertSame(0.0, $c->borderWidth->left->points);
        self::assertGreaterThan(0.0, $c->borderWidth->bottom->points);
        self::assertSame(0.0, $c->radius);
    }

    public function test_badge_outline_draws_the_tone_border(): void
    {
        $c = $this->resolve(Badge::make('x')->tone('danger')->variant('outline')->render());
        self::assertEquals(Color::named('red-300'), $c->borderColor);
        self::assertGreaterThan(0.0, $c->borderWidth->top->points);
        self::assertEquals(Color::named('transparent'), $c->background);
        self::assertEquals(Color::named('red-800'), $c->color);
    }

    public function test_filled_badge_draws_no_border(): void
    {
        self::assertSame(0.0, $this->resolve(Badge::make('x')->tone('danger')->render())->borderWidth->top->points);
    }

    public function test_card_flat_has_no_border(): void
    {
        $c = $this->resolve(Card::make('x')->title('T')->variant('flat')->render());
        self::assertSame(0.0, $c->borderWidth->top->points);
        self::assertEquals(Color::named('slate-50'), $c->background);
    }

    public function test_section_large_title_is_bigger(): void
    {
        $theme = Theme::default();
        $resolver = new StyleResolver($theme);
        $plain = Section::make('x')->title('T')->render();
        $large = Section::make('x')->title('T')->variant('large')->render();
        $titleSize = function (\Papercut\View $section) use ($resolver, $theme): float {
            $root = $resolver->resolve($section, ComputedStyle::root($theme));
            $heading = $section->children()[0];
            self::assertInstanceOf(\Papercut\View::class, $heading);
            $h = $resolver->resolve($heading, $root);
            $title = $heading->children()[count($heading->children()) - 1] ?? null;
            self::assertInstanceOf(\Papercut\Text::class, $title);
            return $resolver->resolve($title, $h)->fontSize;
        };
        self::assertGreaterThan($titleSize($plain), $titleSize($large));
    }
}
