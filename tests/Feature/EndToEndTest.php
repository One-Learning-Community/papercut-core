<?php

declare(strict_types=1);

namespace Papercut\Tests\Feature;

use Papercut\{Column, Document, Page, PageNumber, Row, Spacer, Table, Text};
use Papercut\Backend\PhpPdf\PhpPdfBackend;
use Papercut\Export\PdfOptions;
use Papercut\Style\Theme;
use Papercut\Tests\Support\PdfInspector;
use PHPUnit\Framework\TestCase;

use function Papercut\{mm, pt};

final class EndToEndTest extends TestCase
{
    private function transcript(int $rows): Document
    {
        $courses = array_map(fn (int $i) => ["Course $i", (string) ($i % 4 + 1), ['A', 'B', 'C'][$i % 3]], range(1, $rows));
        return Document::make(
            Page::a4()->margin(mm(20))
                ->header(Row::make(Text::make('Official Transcript')->bold()->size(pt(16)), Spacer::flex(), Text::make('OLC')))
                ->body(Column::make(
                    Text::h2('Student: Ada Lovelace'),
                    Table::make()->columns('3fr 25mm 20mm')->head('Course', 'Credits', 'Grade')
                        ->rows($courses, fn (array $c) => [$c[0], $c[1], Text::make($c[2])->right()]),
                )->gap(pt(8)))
                ->footer(PageNumber::make('Page {page} of {total}')->center()),
        )->title('Transcript')->createdAt(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
    }

    public function test_multi_page_transcript(): void
    {
        $pdf = $this->transcript(120)->toPdf()->bytes();
        PdfInspector::check($pdf);
        $pages = (int) PdfInspector::info($pdf)['Pages'];
        self::assertGreaterThanOrEqual(2, $pages);
        $text = PdfInspector::text($pdf);
        self::assertStringContainsString("Page 1 of $pages", $text);
        self::assertStringContainsString("Page $pages of $pages", $text);
        // header row repeats on page 2
        $page2 = array_column(PdfInspector::words($pdf, 2), 'text');
        self::assertContains('Course', $page2);
        self::assertContains('Credits', $page2);
        self::assertStringContainsString('Course 120', $text);
    }

    public function test_deterministic(): void
    {
        self::assertSame($this->transcript(30)->toPdf()->bytes(), $this->transcript(30)->toPdf()->bytes());
    }

    public function test_save(): void
    {
        $path = sys_get_temp_dir() . '/papercut-e2e-' . getmypid() . '.pdf';
        try {
            $this->transcript(5)->toPdf()->save($path);
            self::assertFileExists($path);
            PdfInspector::check((string) file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }

    public function test_save_missing_directory_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Directory does not exist');
        $this->transcript(1)->toPdf()->save(sys_get_temp_dir() . '/papercut-no-such-dir-' . getmypid() . '/x.pdf');
    }

    public function test_stream_writes_output_and_returns_byte_count(): void
    {
        $doc = $this->transcript(5);
        $expected = $doc->toPdf()->bytes();
        ob_start();
        $n = $doc->toPdf()->stream();
        $out = (string) ob_get_clean();
        self::assertSame(strlen($expected), $n);
        self::assertSame($expected, $out);
    }

    public function test_save_failure_names_the_path_and_the_error(): void
    {
        $path = sys_get_temp_dir(); // a directory: the write itself fails
        try {
            $this->transcript(1)->toPdf()->save($path);
            self::fail('saved');
        } catch (\RuntimeException $e) {
            self::assertStringStartsWith(sprintf('Could not write output to "%s": ', $path), $e->getMessage());
            self::assertGreaterThan(strlen(sprintf('Could not write output to "%s": ', $path)), strlen($e->getMessage()));
        }
    }

    public function test_render_with_custom_options(): void
    {
        $doc = Document::make(Page::a5()->body(Text::make('Hello options')))->createdAt(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $default = $doc->toPdf()->bytes();
        $themed = $doc->toPdf(new PdfOptions(theme: Theme::default()->with(fontSize: 20.0)))->bytes();
        PdfInspector::check($themed);
        self::assertNotSame($default, $themed);

        $backend = new PhpPdfBackend();
        $pdf = $doc->toPdf(new PdfOptions(backend: $backend))->bytes();
        PdfInspector::check($pdf);
        self::assertStringContainsString('Hello options', PdfInspector::text($pdf));
    }

    public function test_monospace_text_embeds_the_bundled_mono_font(): void
    {
        $pdf = Document::make(Page::a4()->body(Text::make('fixed width')->font('monospace')))->toPdf()->bytes();

        PdfInspector::check($pdf);
        $file = tempnam(sys_get_temp_dir(), 'pdf');
        self::assertIsString($file);
        try {
            file_put_contents($file, $pdf);
            exec('pdffonts ' . escapeshellarg($file) . ' 2>&1', $lines, $code);
        } finally {
            unlink($file);
        }
        self::assertSame(0, $code, implode("\n", $lines));
        $names = implode("\n", array_slice($lines, 2));
        self::assertStringContainsString('Cousine', $names);
        self::assertStringNotContainsString('Inter', $names);
    }
}
