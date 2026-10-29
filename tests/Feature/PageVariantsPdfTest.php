<?php

declare(strict_types=1);

namespace Papercut\Tests\Feature;

use Papercut\{Document, Page, PageNumber, Text};
use Papercut\Tests\Support\PdfInspector;
use PHPUnit\Framework\TestCase;
use function Papercut\pt;

/** First-page and even-page headers and footers, through a full PDF render. */
final class PageVariantsPdfTest extends TestCase
{
    public function test_pdf_shows_first_and_even_headers_on_the_right_pages(): void
    {
        $lines = array_map(fn (int $i) => Text::make("Line $i"), range(1, 30));
        $page = Page::size(pt(300), pt(200))->margin(pt(20))
            ->firstHeader('Letterhead')->header('Running')->evenHeader('Even')
            ->evenFooter(PageNumber::make('Sheet {page}'))
            ->body(...$lines);
        $pdf = Document::make($page)->toPdf()->bytes();
        PdfInspector::check($pdf);
        $words = static fn (int $n): string => implode(' ', array_column(PdfInspector::words($pdf, $n), 'text'));
        self::assertStringContainsString('Letterhead', $words(1));
        self::assertStringNotContainsString('Running', $words(1));
        self::assertStringContainsString('Even', $words(2));
        self::assertStringContainsString('Sheet 2', $words(2));
        self::assertStringContainsString('Running', $words(3));
        self::assertStringNotContainsString('Sheet', $words(3));
    }
}
