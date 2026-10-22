<?php

declare(strict_types=1);

namespace Papercut\Tests\Decorator;

use Papercut\{Component, Document, Node, Page, Text, View};
use Papercut\Decorator\{ChoiceList, ChoiceOption, Decorator};
use Papercut\Layout\Fragment;
use Papercut\Style\{ComputedStyle, Direction, Style, StyleResolver, Theme};
use Papercut\Value\Edges;

use function Papercut\pt;
use Papercut\Tests\Support\LayoutHarness;
use Papercut\Value\Length;
use PHPUnit\Framework\TestCase;

final class ChoiceListTest extends TestCase
{
    private static function view(Node|Component $n): View
    {
        self::assertInstanceOf(View::class, $n);
        return $n;
    }

    /** @return array{View, View, View} row, marker, label */
    private static function row(ChoiceList $list, int $i = 0): array
    {
        $row = self::view($list->render()->children()[$i]);
        [$slot, $label] = $row->children();
        return [$row, self::view(self::view($slot)->children()[0]), self::view($label)];
    }

    public function test_is_a_decorator(): void
    {
        self::assertInstanceOf(Decorator::class, ChoiceList::radio());
        self::assertTrue(ChoiceList::radio()->isRadio());
        self::assertFalse(ChoiceList::checkbox()->isRadio());
    }

    public function test_option_value_class(): void
    {
        $o = ChoiceOption::of('A', true);
        self::assertTrue($o->selected);
        self::assertInstanceOf(Text::class, $o->label);
        self::assertFalse(ChoiceOption::of(Text::make('B'))->selected);
    }

    public function test_one_row_per_option(): void
    {
        $list = ChoiceList::checkbox()->option('A', true)->option('B');
        $outer = $list->render();

        self::assertSame('choice', $outer->styleName());
        self::assertCount(2, $outer->children());
        [$row, , $label] = self::row($list);
        self::assertSame(Direction::Row, $row->style()->direction);
        self::assertSame('choice.option', $row->styleName());
        $theme = Theme::default();
        self::assertSame(6.0, (new StyleResolver($theme))->resolve($row, ComputedStyle::root($theme))->gap);
        self::assertTrue($row->style()->keepTogether);
        self::assertSame(1.0, $label->style()->grow);
    }

    public function test_checkbox_markers(): void
    {
        $list = ChoiceList::checkbox()->option('A')->option('B', true);
        [, $off] = self::row($list, 0);
        [, $on] = self::row($list, 1);

        self::assertSame('choice.marker', $off->styleName());
        self::assertCount(0, $off->children());
        self::assertSame('choice.marker choice.marker.selected', $on->styleName());
        self::assertCount(1, $on->children());
        self::assertSame('choice.mark', self::view($on->children()[0])->styleName());
    }

    public function test_radio_markers(): void
    {
        $list = ChoiceList::radio()->option('A')->option('B', true);
        [, $off] = self::row($list, 0);
        [, $on] = self::row($list, 1);

        self::assertSame('choice.marker choice.marker.radio', $off->styleName());
        self::assertSame('choice.marker choice.marker.radio choice.marker.selected', $on->styleName());
        self::assertSame('choice.mark choice.mark.radio', self::view($on->children()[0])->styleName());
    }

    public function test_radio_allows_several_selected(): void
    {
        $list = ChoiceList::radio()->option('A', true)->option('B', true);
        self::assertCount(1, self::row($list, 0)[1]->children());
        self::assertCount(1, self::row($list, 1)[1]->children());
    }

    public function test_emphasize_selected(): void
    {
        $list = ChoiceList::checkbox()->option('A', true)->option('B');
        self::assertNull(self::row($list, 0)[2]->styleName());

        $e = $list->emphasizeSelected();
        self::assertSame('choice.label.selected', self::row($e, 0)[2]->styleName());
        self::assertNull(self::row($e, 1)[2]->styleName());
        self::assertNull(self::row($e->emphasizeSelected(false), 0)[2]->styleName());
    }

    public function test_add_and_options_and_immutability(): void
    {
        $base = ChoiceList::radio();
        $list = $base->add(ChoiceOption::of('A'), ChoiceOption::of('B', true));

        self::assertCount(0, $base->options());
        self::assertCount(2, $list->options());
        self::assertTrue($list->options()[1]->selected);
    }

    public function test_style_name_and_style_on_outer(): void
    {
        $theme = Theme::default()->withStyle('mine', Style::make(gap: pt(20), padding: Edges::all(pt(7))));
        $outer = ChoiceList::radio()->apply('mine')->padding(pt(9))->render();
        self::assertSame('choice mine', $outer->styleName());

        $resolved = (new StyleResolver($theme))->resolve($outer, ComputedStyle::root($theme));
        self::assertSame(20.0, $resolved->gap);
        self::assertSame(9.0, $resolved->padding->top->points);
    }

    public function test_resolved_radio_marker(): void
    {
        $theme = Theme::default();
        [, $marker] = self::row(ChoiceList::radio()->option('A'));
        $r = (new StyleResolver($theme))->resolve($marker, ComputedStyle::root($theme));

        // 0.75em at the default 10pt: a 7.5pt circle.
        self::assertEqualsWithDelta(3.75, $r->radius, 1e-9);
        self::assertInstanceOf(Length::class, $r->width);
        self::assertEqualsWithDelta(7.5, $r->width->points, 1e-9);
    }

    public function test_empty_list_renders_and_exports(): void
    {
        $list = ChoiceList::checkbox();
        self::assertCount(0, $list->render()->children());
        self::assertNotSame('', Document::make(Page::a4()->body($list))->toPdf()->bytes());
    }

    public function test_renders_in_pdf_and_html(): void
    {
        $doc = Document::make(Page::a4()->body(ChoiceList::radio()->option('Distinctive label', true)->option('B')));
        self::assertNotSame('', $doc->toPdf()->bytes());
        self::assertStringContainsString('Distinctive label', $doc->toHtml()->bytes());
    }

    public function test_marker_aligns_with_first_line_of_wrapped_label(): void
    {
        // Default theme: 10pt at 1.4, so a 7.5pt marker centred on a 14pt line sits 3.25pt down.
        self::assertMarkerCentred(new LayoutHarness(), 7.5, 3.25);
    }

    public function test_marker_follows_the_theme_size(): void
    {
        // 12pt at 1.5: a 9pt marker centred on an 18pt line sits 4.5pt down.
        self::assertMarkerCentred(new LayoutHarness(Theme::default()->with(fontSize: 12.0, lineHeight: 1.5)), 9.0, 4.5);
    }

    private static function assertMarkerCentred(LayoutHarness $h, float $size, float $offset): void
    {
        $f = $h->layout(ChoiceList::checkbox()->option(str_repeat('word ', 40), true), 200)->fragment;
        self::assertNotNull($f);

        $row = $f->children()[0]['fragment'];
        [$slot, $label] = $row->children();
        $marker = $slot['fragment']->children()[0];
        self::assertGreaterThan(2 * $size, $label['fragment']->height, 'label wraps');
        self::assertLessThanOrEqual(200.0, $label['x'] + $label['fragment']->width);
        self::assertEqualsWithDelta($label['y'] + $offset, $slot['y'] + $marker['y'], 0.01);
        self::assertEqualsWithDelta($size, $marker['fragment']->width, 0.01);
    }
}
