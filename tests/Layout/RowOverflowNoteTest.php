<?php

declare(strict_types=1);

namespace Papercut\Tests\Layout;

use Papercut\{Document, Image, Page, Row, Table, Text, View};
use Papercut\Export\PdfOptions;
use Papercut\Tests\Support\{FailsOnDiagnostics, LayoutHarness, TestImages};
use Papercut\Value\Length;
use PHPUnit\Framework\TestCase;

use function Papercut\{mm, pt};

/** A row item that cannot shrink runs past the row; that is reported once, not silent (R7). */
final class RowOverflowNoteTest extends TestCase
{
    use FailsOnDiagnostics;

    private const NOTE = 'row content wider than the row';

    public function test_two_min_width_items_wider_than_the_row_are_noted_once(): void
    {
        $row = Row::make(Text::make('a')->minWidth(pt(400)), Text::make('b')->minWidth(pt(400)));
        $h = new LayoutHarness();
        $h->layout($row, 481);
        self::assertCount(1, $h->warnings, implode("\n", $h->warnings));
        self::assertStringContainsString(self::NOTE, $h->warnings[0]);
        // laid out again (a second page, a trial): still one
        $h->layout($row, 481);
        self::assertCount(1, $h->warnings);
    }

    public function test_a_picture_that_may_not_shrink_is_noted(): void
    {
        $row = Row::make(Text::make('a'), Image::bytes(TestImages::png())->width(pt(2000))->shrink(0));
        $h = new LayoutHarness();
        $h->layout($row, 400);
        self::assertCount(1, array_filter($h->warnings, static fn (string $w): bool => str_contains($w, self::NOTE)), implode("\n", $h->warnings));
    }

    public function test_rows_that_fit_or_shrink_make_no_note(): void
    {
        $h = new LayoutHarness();
        $h->layout(Row::make(Text::make('aa'), Text::make('bbb')), 200);
        $h->layout(Row::make(Text::make('a')->minWidth(pt(100)), Text::make('b')->minWidth(pt(100))), 200);
        $h->layout(Row::make(Text::make('LEFTT'), Text::make(str_repeat('W', 80))), 100);
        $h->layout(Row::make(Text::make('a'), Image::bytes(TestImages::png())->width(pt(2000))), 400);
        self::assertSame([], $h->warnings);
    }

    public function test_a_250mm_picture_in_a_page_row_is_reported_in_the_pdf(): void
    {
        $doc = Document::make(Page::a4()->margin(mm(15))->body(Row::make(Image::bytes(TestImages::png())->width(mm(250))->shrink(0))));
        $notes = [];
        $doc->toPdf(new PdfOptions(onWarning: static function (string $m) use (&$notes): void {
            $notes[] = $m;
        }, cacheDir: false));
        self::assertCount(1, array_filter($notes, static fn (string $n): bool => str_contains($n, self::NOTE)), implode("\n", $notes));
    }

    public function test_a_250mm_picture_in_a_page_row_scales_down_without_a_note(): void
    {
        $doc = Document::make(Page::a4()->margin(mm(15))->body(Row::make(Image::bytes(TestImages::png())->width(mm(250)))));
        $notes = [];
        $doc->toPdf(new PdfOptions(onWarning: static function (string $m) use (&$notes): void {
            $notes[] = $m;
        }, cacheDir: false));
        self::assertSame([], $notes);
    }

    public function test_a_table_in_a_view_in_a_row_is_narrowed_with_its_note_only(): void
    {
        $table = Table::make()->columns('auto auto')->row('aa', str_repeat('a', 40));
        $h = new LayoutHarness();
        $f = $h->layout(Row::make(View::make($table)), 100)->fragment;
        self::assertNotNull($f);
        self::assertCount(1, $h->warnings, implode("\n", $h->warnings));
        self::assertStringContainsString('table columns narrower than their content', $h->warnings[0]);
    }
}
