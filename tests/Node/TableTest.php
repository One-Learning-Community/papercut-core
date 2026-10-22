<?php

declare(strict_types=1);

namespace Papercut\Tests\Node;

use Papercut\{Table, TableCell, TableRow, Text};
use Papercut\Value\{Fr, Length};
use PHPUnit\Framework\TestCase;

use function Papercut\mm;

final class TableTest extends TestCase
{
    public function test_convenience_api_builds_rows(): void
    {
        $courses = [['Math', 4, 'A'], ['Art', 2, 'B+']];
        $t = Table::make()
            ->columns('3fr 25mm 20mm')
            ->head('Course', 'Credits', 'Grade')
            ->rows($courses, fn (array $c) => [$c[0], $c[1], Text::make($c[2])->right()]);
        self::assertSame(3, $t->trackList()?->count());
        self::assertInstanceOf(Fr::class, $t->trackList()->tracks[0]);
        self::assertCount(1, $t->headerRows());
        self::assertTrue($t->headerRows()[0]->cells()[0]->header());
        self::assertSame('th', $t->headerRows()[0]->cells()[0]->debugName());
        self::assertCount(2, $t->bodyRows());
        self::assertSame('td', $t->bodyRows()[1]->cells()[1]->debugName());
        self::assertTrue($t->repeatsHeader());
        self::assertFalse($t->repeatHeader(false)->repeatsHeader());
    }

    public function test_columns_variadic_typed(): void
    {
        $t = Table::make()->columns(mm(10), \Papercut\fr(1));
        self::assertInstanceOf(Length::class, $t->trackList()?->tracks[0]);
    }

    public function test_colspan_validation(): void
    {
        self::assertSame(2, TableCell::make('x')->colspan(2)->span());
        $this->expectException(\InvalidArgumentException::class);
        TableCell::make('x')->colspan(0);
    }

    public function test_row_wraps_non_cells(): void
    {
        $r = TableRow::make('a', TableCell::make('b'));
        self::assertCount(2, $r->cells());
    }

    // --- Final review ---

    public function test_head_without_cells_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Table::make()->head();
    }

    public function test_columns_without_tracks_throws(): void
    {
        $this->expectException(\Papercut\Exception\InvalidValue::class);
        Table::make()->columns();
    }
}
