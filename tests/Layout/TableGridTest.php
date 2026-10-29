<?php

declare(strict_types=1);

namespace Papercut\Tests\Layout;

use Papercut\{Table, TableCell};
use Papercut\Layout\TableGrid;
use PHPUnit\Framework\TestCase;

final class TableGridTest extends TestCase
{
    public function test_rowspan_pushes_later_cells_right(): void
    {
        $t = Table::make()->row(TableCell::make('a')->rowspan(2), 'b')->row('c');
        $g = TableGrid::of($t);
        self::assertSame(2, $g->columns);
        self::assertSame([[0, 0], [0, 1]], array_map(fn ($p) => [$p->row, $p->col], $g->placements[0]));
        self::assertSame(1, $g->placements[1][0]->col);
        self::assertSame(1, $g->groupEnd(0));
    }

    public function test_rowspan_is_clamped_at_the_section_end(): void
    {
        $t = Table::make()->head(TableCell::th('h')->rowspan(3))->row('x');
        $g = TableGrid::of($t);
        self::assertSame(1, $g->placements[0][0]->rowspan);
        self::assertSame([0], $g->clampedRowspans);
        self::assertSame([], $g->clampedColspans);
        self::assertSame(0, $g->placements[1][0]->col);
        self::assertSame(0, $g->groupEnd(0));
    }

    public function test_colspan_stops_at_a_column_covered_by_a_rowspan(): void
    {
        $t = Table::make()->row('a', 'b', TableCell::make('c')->rowspan(2))->row(TableCell::make('d')->colspan(3));
        $g = TableGrid::of($t);
        self::assertSame(0, $g->placements[1][0]->col);
        self::assertSame(2, $g->placements[1][0]->colspan);
        self::assertSame([1], $g->clampedColspans);
        self::assertSame([], $g->clampedRowspans);
        self::assertSame(3, $g->columns);
    }

    public function test_ragged_rows_with_rowspan_leave_empty_slots(): void
    {
        $t = Table::make()
            ->row(TableCell::make('a')->rowspan(3), 'b', 'c')
            ->row('d')
            ->row('e', 'f', 'g');
        $g = TableGrid::of($t);
        self::assertSame(4, $g->columns);
        self::assertSame(1, $g->placements[1][0]->col);
        self::assertSame([1, 2, 3], array_map(fn ($p) => $p->col, $g->placements[2]));
        self::assertSame(2, $g->groupEnd(0));
    }

    public function test_colspan_and_rowspan_together(): void
    {
        $t = Table::make()->row(TableCell::make('a')->colspan(2)->rowspan(2), 'b')->row('c');
        $g = TableGrid::of($t);
        self::assertSame(2, $g->placements[1][0]->col);
        self::assertSame(3, $g->columns);
        self::assertSame(1, $g->groupEnd(0));
    }

    public function test_rows_without_spans_are_one_row_groups(): void
    {
        $g = TableGrid::of(Table::make()->row('a', 'b')->row('c', 'd'));
        self::assertSame(0, $g->groupEnd(0));
        self::assertSame(1, $g->groupEnd(1));
        self::assertSame([], $g->clampedRowspans);
        self::assertSame([], $g->clampedColspans);
    }

    public function test_empty_table_has_no_columns(): void
    {
        self::assertSame(0, TableGrid::of(Table::make())->columns);
    }
}
