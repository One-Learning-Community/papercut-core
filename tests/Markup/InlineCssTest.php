<?php

declare(strict_types=1);

namespace Papercut\Tests\Markup;

use Papercut\Markup\InlineCss;
use Papercut\Style\ListMarker;
use Papercut\Style\TextAlign;
use Papercut\Value\Length;
use Papercut\Value\Percent;
use PHPUnit\Framework\TestCase;

final class InlineCssTest extends TestCase
{
    public function test_parse(): void
    {
        $r = InlineCss::parse('width: 221px; max-width: 221px; height: 266px; display: flex; float: right', inline: false);
        self::assertInstanceOf(Length::class, $r['style']->width);
        self::assertEqualsWithDelta(165.75, $r['style']->width->points, 1e-9);
        self::assertSame(['display: flex'], $r['dropped']);
        self::assertTrue($r['float']);
        self::assertSame(['text-align: center'], InlineCss::parse('text-align: center; color: #c00', inline: true)['dropped']);
    }

    public function test_text_properties(): void
    {
        $s = InlineCss::parse('COLOR: #F00; font-size: 12px; font-weight: 600; font-style: italic; text-decoration: underline', inline: true);
        self::assertSame([], $s['dropped']);
        self::assertSame('#ff0000', (string) $s['style']->color);
        self::assertEqualsWithDelta(9.0, $s['style']->fontSize?->points, 1e-9);
        self::assertSame(600, $s['style']->fontWeight);
        self::assertTrue($s['style']->italic);
        self::assertTrue($s['style']->underline);

        $n = InlineCss::parse('font-weight: normal; font-style: normal; text-decoration: none', inline: true)['style'];
        self::assertSame(400, $n->fontWeight);
        self::assertFalse($n->italic);
        self::assertFalse($n->underline);
        self::assertFalse($n->strike);
        self::assertTrue(InlineCss::parse('text-decoration: line-through', inline: true)['style']->strike);
        self::assertSame(700, InlineCss::parse('font-weight: bold', inline: true)['style']->fontWeight);
    }

    public function test_block_properties(): void
    {
        $r = InlineCss::parse('text-align: Justify; background-color: #eee; width: 50%; min-height: 1in; margin: 0 8px; padding: 4px', inline: false);
        self::assertSame([], $r['dropped']);
        self::assertFalse($r['float']);
        $s = $r['style'];
        self::assertSame(TextAlign::Justify, $s->textAlign);
        self::assertSame('#eeeeee', (string) $s->background);
        self::assertInstanceOf(Percent::class, $s->width);
        self::assertInstanceOf(Length::class, $s->minHeight);
        self::assertEqualsWithDelta(72.0, $s->minHeight->points, 1e-9);
        self::assertEqualsWithDelta(6.0, $s->margin?->right->points ?? 0, 1e-9);
        self::assertEqualsWithDelta(3.0, $s->padding?->top->points ?? 0, 1e-9);
    }

    public function test_rejected_values_are_dropped_as_written(): void
    {
        $r = InlineCss::parse(' font-size: 1.2em ;font-weight: 950; color: notacolor; background-color: red; width: auto; margin: 1px 2px 3px 4px 5px; text-align: start; '
            . 'font-style: oblique; text-decoration: underline overline; float: inherit; nonsense;; ', inline: false);
        self::assertSame([
            'font-size: 1.2em', 'font-weight: 950', 'color: notacolor', 'background-color: red', 'width: auto', 'margin: 1px 2px 3px 4px 5px',
            'text-align: start', 'font-style: oblique', 'text-decoration: underline overline', 'float: inherit', 'nonsense',
        ], $r['dropped']);
        self::assertTrue($r['style']->isEmpty());
        self::assertFalse($r['float']);
        self::assertSame(['font-size: 0px', 'font-size: -2pt'], InlineCss::parse('font-size: 0px; font-size: -2pt', inline: true)['dropped']);
    }

    public function test_inline_elements_take_text_properties_only(): void
    {
        $r = InlineCss::parse('float: left; float: none; margin: 4px; color: rgb(0, 0, 255)', inline: true);
        self::assertSame(['float: left', 'margin: 4px'], $r['dropped']);
        self::assertFalse($r['float']);
        self::assertNotNull($r['style']->color);
        self::assertNull($r['style']->margin);
    }

    public function test_list_style_type_only_where_lists_take_it(): void
    {
        self::assertSame(ListMarker::UpperRoman, InlineCss::parse('list-style-type: upper-roman', inline: false, list: true)['marker']);
        self::assertSame(['list-style-type: square'], InlineCss::parse('list-style-type: square', inline: false)['dropped']);
        self::assertSame(['list-style-type: square'], InlineCss::parse('list-style-type: square', inline: true, list: true)['dropped']);
        self::assertSame(['list-style-type: georgian'], InlineCss::parse('list-style-type: georgian', inline: false, list: true)['dropped']);
        self::assertNull(InlineCss::parse('', inline: false, list: true)['marker']);
    }

    public function test_important_is_stripped_and_units_are_case_insensitive(): void
    {
        $r = InlineCss::parse('color: #c00 !important; width: 12PX; margin: 0 8Px!IMPORTANT; font-size: 1.5PT', inline: false);
        self::assertSame([], $r['dropped']);
        self::assertSame('#cc0000', (string) $r['style']->color);
        self::assertEqualsWithDelta(9.0, $r['style']->width instanceof Length ? $r['style']->width->points : -1, 1e-9);
        self::assertEqualsWithDelta(6.0, $r['style']->margin?->right->points ?? 0, 1e-9);
        self::assertEqualsWithDelta(1.5, $r['style']->fontSize->points ?? 0, 1e-9);
        self::assertSame(['width: auto !important'], InlineCss::parse('width: auto !important', inline: false)['dropped']);
    }
}
