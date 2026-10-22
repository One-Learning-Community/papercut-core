<?php

declare(strict_types=1);

namespace Papercut\Tests\Value;

use Papercut\Exception\InvalidValue;
use Papercut\Style\{ComputedStyle, Style, Theme};
use Papercut\Value\{Edges, Length, Percent, TrackList};
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

/** em and lh: lengths relative to the element's font size and line height, resolved when styles are computed. */
final class RelativeLengthTest extends TestCase
{
    public function test_parse_em_and_lh_in_style_context(): void
    {
        self::assertSame('1.5em', (string) Length::parse('1.5em', relative: true));
        self::assertSame('2lh', (string) Length::parse('2lh', relative: true));
        self::assertTrue(Length::em(1)->isRelative());
        self::assertFalse(pt(3)->isRelative());
    }

    public function test_relative_units_rejected_outside_styles(): void
    {
        $this->expectException(InvalidValue::class);
        Length::parse('2em');
    }

    public function test_track_lists_stay_absolute(): void
    {
        $this->expectException(InvalidValue::class);
        TrackList::parse('2em 1fr');
    }

    public function test_style_attributes_accept_em_and_lh(): void
    {
        $s = Style::fromAttributes(['padding' => '0.5em 1em', 'min-height' => '3lh', 'font-size' => '1.2em', 'gap' => '1lh']);
        self::assertSame('0.5em', (string) $s->padding?->top);
        self::assertInstanceOf(Length::class, $s->minHeight);
        self::assertSame('3lh', (string) $s->minHeight);
    }

    public function test_computed_style_resolves_against_own_font_size_and_line_height(): void
    {
        $root = ComputedStyle::root(Theme::default()->with(fontSize: 12.0, lineHeight: 1.5));
        $c = $root->child(new Style(
            padding: Edges::of(Length::em(0.5), Length::em(1)),
            minHeight: Length::lh(3),
            gap: Length::lh(1),
            radius: Length::em(0.25),
            letterSpacing: Length::em(0.1),
        ));

        self::assertEqualsWithDelta(6.0, $c->padding->top->points, 1e-9);
        self::assertEqualsWithDelta(12.0, $c->padding->right->points, 1e-9);
        self::assertInstanceOf(Length::class, $c->minHeight);
        self::assertEqualsWithDelta(54.0, $c->minHeight->points, 1e-9, '3 × 12pt × 1.5');
        self::assertEqualsWithDelta(18.0, $c->gap, 1e-9);
        self::assertEqualsWithDelta(3.0, $c->radius, 1e-9);
        self::assertEqualsWithDelta(1.2, $c->letterSpacing, 1e-9);
    }

    public function test_font_size_in_em_is_relative_to_the_parent_and_other_ems_follow_it(): void
    {
        $root = ComputedStyle::root(Theme::default()->with(fontSize: 10.0, lineHeight: 1.4));
        $c = $root->child(new Style(fontSize: Length::em(2), padding: Edges::all(Length::em(1)), minHeight: Length::lh(1)));

        self::assertEqualsWithDelta(20.0, $c->fontSize, 1e-9);
        self::assertEqualsWithDelta(20.0, $c->padding->top->points, 1e-9, 'em on other properties uses the element\'s own size');
        self::assertInstanceOf(Length::class, $c->minHeight);
        self::assertEqualsWithDelta(28.0, $c->minHeight->points, 1e-9);
    }

    public function test_percent_and_absolute_lengths_untouched(): void
    {
        $c = ComputedStyle::root(Theme::default())->child(new Style(width: Percent::of(50), minHeight: pt(7)));
        self::assertInstanceOf(Percent::class, $c->width);
        self::assertInstanceOf(Length::class, $c->minHeight);
        self::assertSame(7.0, $c->minHeight->points);
    }

    public function test_non_positive_relative_font_size_rejected(): void
    {
        $this->expectException(InvalidValue::class);
        ComputedStyle::root(Theme::default())->child(new Style(fontSize: Length::em(0)));
    }
}
