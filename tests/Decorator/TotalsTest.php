<?php

declare(strict_types=1);

namespace Papercut\Tests\Decorator;

use InvalidArgumentException;
use Papercut\{Table, Text, View};
use Papercut\Decorator\{Decorator, KeyValueList, KeyValuePair, Totals};
use Papercut\Style\{Align, ComputedStyle, StyleResolver, TextAlign, Theme};
use Papercut\Value\TrackList;
use PHPUnit\Framework\TestCase;

final class TotalsTest extends TestCase
{
    public function test_is_a_decorator(): void
    {
        self::assertInstanceOf(Decorator::class, Totals::make());
    }

    public function test_rows_and_totals(): void
    {
        $table = Totals::make()->row('Subtotal', '$1,000.00')->row('Tax', '$82.50')->total('Total due', '$1,082.50')->render();

        self::assertInstanceOf(Table::class, $table);
        self::assertSame('totals', $table->styleName());
        self::assertEquals(TrackList::parse('1fr auto'), $table->trackList());
        self::assertCount(3, $table->bodyRows());
        [$label, $value] = $table->bodyRows()[0]->cells();
        self::assertSame('totals.label', $label->styleName());
        self::assertSame('totals.value', $value->styleName());
        [$label, $value] = $table->bodyRows()[2]->cells();
        self::assertSame('totals.label totals.total-label', $label->styleName());
        self::assertSame('totals.value totals.total-value', $value->styleName());
    }

    public function test_any_row_can_be_a_total(): void
    {
        $table = Totals::make()->total('Total', '$10')->row('Paid', '$4')->total('Balance due', '$6')->render();

        self::assertInstanceOf(Table::class, $table);
        $flags = array_map(fn ($r) => str_contains((string) $r->cells()[0]->styleName(), 'total-label'), $table->bodyRows());
        self::assertSame([true, false, true], $flags);
    }

    public function test_add_takes_pairs_with_their_flag(): void
    {
        $totals = Totals::make(KeyValuePair::of('A', '1'))->add(KeyValuePair::of('B', '2', total: true));

        self::assertCount(2, $totals->pairs());
        self::assertFalse($totals->pairs()[0]->total);
        self::assertTrue($totals->pairs()[1]->total);
    }

    public function test_empty_is_an_empty_view(): void
    {
        $empty = Totals::make()->render();
        self::assertInstanceOf(View::class, $empty);
        self::assertSame('totals', $empty->styleName());
    }

    public function test_variants_follow_every_part(): void
    {
        $table = Totals::make()->total('T', '1')->variant('wide')->render();

        self::assertInstanceOf(Table::class, $table);
        self::assertSame('totals totals.wide?', $table->styleName());
        self::assertSame('totals.label totals.label.wide? totals.total-label totals.total-label.wide?', $table->bodyRows()[0]->cells()[0]->styleName());
    }

    public function test_default_look(): void
    {
        $theme = Theme::default();
        $resolver = new StyleResolver($theme);
        $table = Totals::make()->row('A', '1')->total('T', '2')->render();
        self::assertInstanceOf(Table::class, $table);
        $outer = $resolver->resolve($table, ComputedStyle::root($theme));
        self::assertSame(Align::End, $outer->alignSelf);
        $value = $resolver->resolve($table->bodyRows()[0]->cells()[1], $outer);
        self::assertSame(TextAlign::Right, $value->textAlign);
        $totalValue = $resolver->resolve($table->bodyRows()[1]->cells()[1], $outer);
        self::assertSame(700, $totalValue->fontWeight);
        self::assertGreaterThan(0.0, $totalValue->borderWidth->top->points);
    }

    public function test_key_value_list_rejects_total_pairs(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A total row belongs in Totals, not KeyValueList.');
        KeyValueList::make(KeyValuePair::of('T', '1', total: true));
    }

    public function test_key_value_list_add_rejects_total_pairs(): void
    {
        $this->expectException(InvalidArgumentException::class);
        KeyValueList::make()->add(KeyValuePair::of('T', '1', total: true));
    }

    public function test_pair_defaults_to_not_total(): void
    {
        $pair = KeyValuePair::of('A', Text::make('1'));
        self::assertFalse($pair->total);
    }

    public function test_beside_a_growing_paragraph_it_keeps_its_width(): void
    {
        $notes = \Papercut\View::make(Text::make(str_repeat('Payment is due within thirty days of the invoice date. ', 8)))->grow(1);
        $row = \Papercut\Row::make($notes, Totals::make()->row('Subtotal', '$1,000.00')->total('Total due', '$1,082.50'))->gap(\Papercut\pt(16));
        $f = (new \Papercut\Tests\Support\LayoutHarness())->layout($row, 400)->fragment;
        self::assertNotNull($f);
        $widths = array_map(fn (array $c): float => $c['fragment']->width, $f->children());
        self::assertEqualsWithDelta(180.0, $widths[1], 1e-6); // 45% of 400
        self::assertEqualsWithDelta(400.0 - 16.0 - 180.0, $widths[0], 1e-6);
    }
}
