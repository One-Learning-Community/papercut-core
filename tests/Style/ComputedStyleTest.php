<?php

declare(strict_types=1);

namespace Papercut\Tests\Style;

use Papercut\Style\{Align, ComputedStyle, Direction, Style, TextAlign, Theme};
use Papercut\Value\{Auto, Color, Edges};
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

final class ComputedStyleTest extends TestCase
{
    public function test_root_comes_from_theme(): void
    {
        $root = ComputedStyle::root(Theme::default());
        self::assertSame('Inter', $root->fontFamily);
        self::assertSame(10.0, $root->fontSize);
        self::assertSame(400, $root->fontWeight);
        self::assertSame('#0f172a', (string) $root->color);
        self::assertSame(1.4, $root->lineHeight);
        self::assertEqualsWithDelta(14.0, $root->lineHeightPoints(), 1e-9);
        self::assertSame(2, $root->minLines);
        self::assertSame(TextAlign::Left, $root->textAlign);
        self::assertSame(Direction::Column, $root->direction);
        self::assertSame(Align::Stretch, $root->alignItems);
        self::assertInstanceOf(Auto::class, $root->width);
    }

    public function test_text_props_inherit_box_props_do_not(): void
    {
        $parent = ComputedStyle::root(Theme::default())->child(Style::make(
            color: Color::white(),
            fontSize: pt(14),
            padding: Edges::all(pt(10)),
            background: Color::black(),
            keepTogether: true,
            minLines: 3,
        ));
        $child = $parent->child(Style::make());
        self::assertSame('#ffffff', (string) $child->color);
        self::assertSame(14.0, $child->fontSize);
        self::assertSame(3, $child->minLines);
        self::assertSame(0.0, $child->padding->top->points);
        self::assertNull($child->background);
        self::assertFalse($child->keepTogether);
    }

    public function test_letter_spacing_resolves_to_points(): void
    {
        $c = ComputedStyle::root(Theme::default())->child(Style::make(letterSpacing: pt(1.5)));
        self::assertSame(1.5, $c->letterSpacing);
    }

    public function test_zero_font_size_and_line_height_are_rejected(): void
    {
        $root = ComputedStyle::root(Theme::default());
        try {
            $root->child(Style::make(fontSize: pt(0)));
            self::fail('expected');
        } catch (\Papercut\Exception\InvalidValue $e) {
            self::assertSame('font size', $e->kind);
        }
        $this->expectException(\Papercut\Exception\InvalidValue::class);
        $root->child(Style::make(lineHeight: 0.0));
    }
}
