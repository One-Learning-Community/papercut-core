<?php

declare(strict_types=1);

namespace Papercut\Tests\Layout;

use Papercut\{Column, PageBreak, Row, Spacer, Table, TableCell, Text};
use Papercut\Layout\{Fragment, WidthResolver};
use Papercut\Style\{ComputedStyle, Justify, Theme};
use Papercut\Tests\Support\LayoutHarness;
use PHPUnit\Framework\TestCase;

use function Papercut\{mm, pt};

final class WidthResolverTest extends TestCase
{
    private const float TOL = 1e-6;

    public function test_flex_slots_match_layout_fragments(): void
    {
        $row = Row::make(
            Text::make('grow')->grow(1),
            Text::make('fixed')->width(pt(50))->shrink(0),
            Spacer::flex(),
            Text::make('tail'),
        )->gap(pt(6))->justify(Justify::SpaceBetween);

        $h = new LayoutHarness();
        $style = $h->engine->styleOf($row, ComputedStyle::root(Theme::default()));
        $lines = (new WidthResolver($h->engine))->flexLines($row, $style, 400.0);
        $frag = $h->layout($row, 400)->fragment;
        self::assertNotNull($frag);

        self::assertCount(1, $lines);
        self::assertCount(4, $lines[0]);
        $ox = $style->borderWidth->left->points + $style->padding->left->points;
        $children = $frag->children();
        foreach ($lines[0] as $k => $slot) {
            self::assertSame($k, $slot->index);
            self::assertEqualsWithDelta($children[$k]['x'], $ox + $slot->x, self::TOL);
            self::assertEqualsWithDelta($children[$k]['fragment']->width, $slot->width, self::TOL);
        }
    }

    public function test_wrap_produces_one_list_per_line(): void
    {
        $row = Row::make(
            Text::make('aaaaaaaaaa')->width(pt(90)),
            PageBreak::make(),
            Text::make('bbbbbbbbbb')->width(pt(90)),
            Text::make('cccccccccc')->width(pt(90)),
        )->wrap()->gap(pt(5));

        $h = new LayoutHarness();
        $style = $h->engine->styleOf($row, ComputedStyle::root(Theme::default()));
        $lines = (new WidthResolver($h->engine))->flexLines($row, $style, 200.0);
        self::assertCount(2, $lines);
        self::assertSame([0, 2], array_map(static fn ($s) => $s->index, $lines[0]));
        self::assertSame([3], array_map(static fn ($s) => $s->index, $lines[1]));
        self::assertSame([], $h->warnings);

        $frag = $h->layout($row, 200)->fragment;
        self::assertNotNull($frag);
        $children = $frag->children();
        $k = 0;
        foreach ($lines as $line) {
            foreach ($line as $slot) {
                self::assertEqualsWithDelta($children[$k]['x'], $slot->x, self::TOL);
                self::assertEqualsWithDelta($children[$k]['fragment']->width, $slot->width, self::TOL);
                $k++;
            }
        }
        self::assertSame(3, $k);
    }

    public function test_row_inside_padded_bordered_column_uses_offered_width(): void
    {
        $row = Row::make(Text::make('a')->grow(1), Text::make('b')->grow(2))->gap(pt(4));
        $column = Column::make($row)->padding(pt(20))->border(pt(2));

        $h = new LayoutHarness();
        $colStyle = $h->engine->styleOf($column, ComputedStyle::root(Theme::default()));
        $rowStyle = $h->engine->styleOf($row, $colStyle);
        $offered = 400.0 - 44.0;
        $lines = (new WidthResolver($h->engine))->flexLines($row, $rowStyle, $offered);

        $outer = $h->layout($column, 400)->fragment;
        self::assertNotNull($outer);
        $rowFrag = $outer->children()[0]['fragment'];
        self::assertEqualsWithDelta($offered, $rowFrag->width, self::TOL);
        foreach ($lines[0] as $k => $slot) {
            $child = $rowFrag->children()[$k];
            self::assertEqualsWithDelta($child['x'], $slot->x, self::TOL);
            self::assertEqualsWithDelta($child['fragment']->width, $slot->width, self::TOL);
        }
        self::assertEqualsWithDelta($offered, $lines[0][1]->x + $lines[0][1]->width, self::TOL);
    }

    public function test_table_columns_match_layout_cell_x(): void
    {
        $table = Table::make()->columns('30mm 2fr 1fr auto')
            ->row('a', 'b', 'c', 'dd')
            ->row(TableCell::make('wide')->colspan(2), 'x', 'y');

        $h = new LayoutHarness();
        $style = $h->engine->styleOf($table, ComputedStyle::root(Theme::default()));
        $widths = (new WidthResolver($h->engine))->tableColumns($table, $style, 400.0);
        $frag = $h->layout($table, 400)->fragment;
        self::assertNotNull($frag);

        self::assertCount(4, $widths);
        self::assertEqualsWithDelta(mm(30)->points, $widths[0], self::TOL);
        self::assertEqualsWithDelta(400.0, array_sum($widths), self::TOL);

        $offsets = [0.0, $widths[0], $widths[0] + $widths[1], $widths[0] + $widths[1] + $widths[2]];
        $rows = $frag->children();
        self::assertCount(2, $rows);
        $first = array_map(static fn (array $c): array => [$c['x'], $c['fragment']->width], $rows[0]['fragment']->children());
        foreach ($first as $k => [$x, $w]) {
            self::assertEqualsWithDelta($offsets[$k], $x, self::TOL);
            self::assertEqualsWithDelta($widths[$k], $w, self::TOL);
        }
        $second = array_map(static fn (array $c): array => [$c['x'], $c['fragment']->width], $rows[1]['fragment']->children());
        self::assertCount(3, $second);
        self::assertEqualsWithDelta(0.0, $second[0][0], self::TOL);
        self::assertEqualsWithDelta($widths[0] + $widths[1], $second[0][1], self::TOL);
        self::assertEqualsWithDelta($offsets[2], $second[1][0], self::TOL);
        self::assertEqualsWithDelta($offsets[3], $second[2][0], self::TOL);
    }

    public function test_auto_columns_use_font_metrics(): void
    {
        $widthOf = function (string $word): float {
            $table = Table::make()->columns('auto 1fr')->row($word, 'x');
            $h = new LayoutHarness();
            $style = $h->engine->styleOf($table, ComputedStyle::root(Theme::default()));
            return (new WidthResolver($h->engine))->tableColumns($table, $style, 400.0)[0];
        };

        self::assertGreaterThan($widthOf('ab'), $widthOf('abcdefghijklmnop'));
    }

    public function test_tracks_match_layout_for_spans_missing_and_extra_tracks(): void
    {
        $fr1 = \Papercut\Value\Fr::of(1);
        $fr2 = \Papercut\Value\Fr::of(2);

        $missing = Table::make()->columns('30mm 2fr')->row('a', 'b', 'c');
        self::assertEquals([mm(30), $fr2, $fr1], WidthResolver::tracks($missing));

        $spanned = Table::make()->columns('10pt 2fr')->row(TableCell::make('wide')->colspan(3));
        self::assertEquals([pt(10), $fr2, $fr1], WidthResolver::tracks($spanned));

        $extra = Table::make()->columns('10pt 20pt 30pt 40pt 50pt')->row('a', 'b');
        self::assertEquals([pt(10), pt(20)], WidthResolver::tracks($extra));

        $h = new LayoutHarness();
        $style = $h->engine->styleOf($missing, ComputedStyle::root(Theme::default()));
        self::assertCount(count(WidthResolver::tracks($missing)), (new WidthResolver($h->engine))->tableColumns($missing, $style, 400.0));
    }
}
