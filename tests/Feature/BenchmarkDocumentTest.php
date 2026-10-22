<?php

declare(strict_types=1);

namespace Papercut\Tests\Feature;

use Papercut\Document;
use Papercut\Tests\Support\PdfInspector;
use PHPUnit\Framework\TestCase;

/** Keeps the benchmark document meaningful: the 150 ms target is for exactly 10 pages. */
final class BenchmarkDocumentTest extends TestCase
{
    public function test_bench_document_has_exactly_ten_pages(): void
    {
        $doc = require __DIR__ . '/../../bench/transcript.php';
        self::assertInstanceOf(Document::class, $doc);
        $pdf = $doc->toPdf()->bytes();
        PdfInspector::check($pdf);
        self::assertSame('10', PdfInspector::info($pdf)['Pages']);
        $text = PdfInspector::text($pdf);
        self::assertStringContainsString('Page 10 of 10', $text);
        self::assertStringContainsString('Course 300', $text);
    }
}
