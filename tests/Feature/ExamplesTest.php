<?php

declare(strict_types=1);

namespace Papercut\Tests\Feature;

use Papercut\Tests\Support\PdfInspector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExamplesTest extends TestCase
{
    /** @return array<string, array{string, int, int}> name, min pages, max pages */
    public static function examples(): array
    {
        return ['invoice' => ['invoice', 1, 1], 'transcript' => ['transcript', 2, \PHP_INT_MAX], 'report' => ['report', 2, \PHP_INT_MAX]];
    }

    #[DataProvider('examples')]
    public function test_example_produces_a_valid_pdf(string $name, int $minPages, int $maxPages): void
    {
        $root = dirname(__DIR__, 4);
        $pdfPath = $root . '/examples/core/' . $name . '.pdf';
        @unlink($pdfPath);

        $output = [];
        $code = -1;
        exec(sprintf('cd %s && %s -d xdebug.mode=off examples/core/%s.php 2>&1', escapeshellarg($root), escapeshellarg(PHP_BINARY), $name), $output, $code);

        self::assertSame(0, $code, implode("\n", $output));
        self::assertFileExists($pdfPath);
        self::assertStringContainsString($name . '.pdf', implode("\n", $output));
        $pdf = (string) file_get_contents($pdfPath);
        PdfInspector::check($pdf);
        $pages = (int) PdfInspector::info($pdf)['Pages'];
        self::assertGreaterThanOrEqual($minPages, $pages);
        self::assertLessThanOrEqual($maxPages, $pages);
    }

    public function test_report_example_makes_no_unbacked_claims(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 4) . '/examples/core/documents/report.php');
        foreach (['PAGES PER SECOND', '38 KB', 'Benchmark', 'shrinking', 'every modifier also accepts a string'] as $claim) {
            self::assertStringNotContainsString($claim, $source);
        }
    }

    public function test_transcript_heading_is_a_sibling_of_the_summary_and_courses_are_distinct(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 4) . '/examples/core/documents/transcript.php');
        self::assertDoesNotMatchRegularExpression('/\$summary = Column::make\(\s*Text::h3/', $source);
        self::assertStringContainsString("Text::h3('Cumulative summary')->keepWithNext(), \$summary", $source);
        $root = dirname(__DIR__, 4);
        exec(sprintf('cd %s && %s -d xdebug.mode=off examples/core/transcript.php 2>&1', escapeshellarg($root), escapeshellarg(PHP_BINARY)));
        $text = PdfInspector::text((string) file_get_contents($root . '/examples/core/transcript.pdf'));
        self::assertDoesNotMatchRegularExpression('/\b(Calculus|Physics|Logic) [2-9]\b/', $text);
    }
}
