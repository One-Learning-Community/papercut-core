<?php

declare(strict_types=1);

namespace Papercut\Tests\Layout;

use Papercut\{Column, Document, Image, Page, Row, Table, Text};
use Papercut\Export\PdfOptions;

use function Papercut\mm;
use Papercut\Tests\Support\LayoutHarness;
use PHPUnit\Framework\TestCase;

/** A table too wide for its row is narrowed like one outside a row, not clipped at the page edge. */
final class TableInRowTest extends TestCase
{
    public function test_an_over_wide_table_in_a_row_is_narrowed_and_reported(): void
    {
        $t = Table::make()->columns('auto auto')->row('aa', str_repeat('a', 20));
        $h = new LayoutHarness();
        $f = $h->layout(Row::make($t), 100)->fragment;
        self::assertNotNull($f);
        self::assertLessThanOrEqual(100.0 + 1e-6, self::rightEdge($f));
        self::assertCount(1, $h->warnings);
        self::assertStringContainsString('approximated table columns narrower than their content', $h->warnings[0]);
    }

    public function test_the_fuzz_shape_keeps_its_last_column_in_the_pdf(): void
    {
        $im = imagecreatetruecolor(100, 50);
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        $img = static fn () => Image::bytes($png)->width(mm(60));
        $table = Table::make()->row($img(), $img(), $img(), 'LASTCOL');
        foreach ([Row::make($table), Row::make(Column::make($table))] as $body) {
            $notes = [];
            $pdf = Document::make(Page::a4()->margin(mm(15))->body($body))->toPdf(new PdfOptions(onWarning: static function (string $m) use (&$notes): void {
                $notes[] = $m;
            }))->bytes();
            self::assertStringContainsString('LASTCOL', self::pdfText($pdf));
            self::assertCount(1, $notes);
            self::assertStringContainsString('narrower than their content', $notes[0]);
        }
    }

    public function test_over_wide_siblings_and_long_words_in_a_row_keep_their_text(): void
    {
        $wide = static fn (string $c, string $last): Table => Table::make()->columns('auto auto')->row(str_repeat($c, 45), $last);
        $cases = [
            [Row::make($wide('a', 'FIRSTT'), $wide('b', 'SECONDT')), ['FIRSTT', 'SECONDT']],
            [Row::make(Text::p(str_repeat('W', 80) . ' LASTCOL')), ['LASTCOL']],
        ];
        foreach ($cases as [$body, $needles]) {
            $text = self::pdfText(Document::make(Page::a4()->margin(mm(15))->body($body))->toPdf()->bytes());
            foreach ($needles as $needle) {
                self::assertStringContainsString($needle, $text);
            }
            self::assertSame(\count($needles) === 1 ? 80 : 0, substr_count($text, 'W'));
        }
        $text = self::pdfText(Document::make(Page::a4()->margin(mm(15))->body(Row::make(Text::p('LEFTT'), Text::p(str_repeat('W', 80)))))->toPdf()->bytes());
        self::assertSame(80, substr_count($text, 'W'));
    }

    private static function pdfText(string $pdf): string
    {
        $file = tempnam(sys_get_temp_dir(), 'row') ?: throw new \RuntimeException('no temp file');
        file_put_contents($file, $pdf);
        try {
            return (string) shell_exec('pdftotext -raw ' . escapeshellarg($file) . ' -');
        } finally {
            unlink($file);
        }
    }

    private static function rightEdge(\Papercut\Layout\Fragment $f, float $ox = 0.0): float
    {
        $right = $ox + $f->width;
        foreach ($f->children() as $c) {
            $right = max($right, self::rightEdge($c['fragment'], $ox + $c['x']));
        }
        return $right;
    }
}
