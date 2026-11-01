<?php

declare(strict_types=1);

namespace Papercut\Tests\Feature;

use Papercut\{Document, Page, Text, Toc};
use Papercut\Export\PdfOptions;
use Papercut\Tests\Support\PdfInspector;
use PHPUnit\Framework\TestCase;

final class TocPdfTest extends TestCase
{
    private static function document(?Toc $toc = null, ?Toc $second = null): Document
    {
        return Document::make(
            Page::a6()->body($toc ?? Toc::make(), ...($second === null ? [] : [$second])),
            Page::a6()->body(Text::h1('Alpha')),
            Page::a6()->body(Text::h1('Beta'), Text::h3('Beta detail')),
        );
    }

    /** The 1-based page a named destination jumps to, read from a qpdf --qdf dump. */
    public static function destinationPage(string $qdf, string $name): int
    {
        if (preg_match('~\(' . preg_quote($name, '~') . '\)\s*\[\s*(\d+) 0 R~', $qdf, $d) !== 1) {
            self::fail("No destination $name");
        }
        self::assertSame(1, preg_match('~%% Page (\d+)\n%% Original object ID: \d+ \d+\n' . $d[1] . ' 0 obj~', $qdf, $m), "page object of $name");
        return (int) $m[1];
    }

    /** @return list<string> */
    private static function words(string $pdf, int $page): array
    {
        return array_map(static fn (array $w): string => $w['text'], PdfInspector::words($pdf, $page));
    }

    public function test_numbers_destinations_and_links(): void
    {
        $pdf = self::document()->toPdf()->bytes();

        PdfInspector::check($pdf);
        $words = self::words($pdf, 1);
        $alpha = array_search('Alpha', $words, true);
        $beta = array_search('Beta', $words, true);
        self::assertNotFalse($alpha);
        self::assertNotFalse($beta);
        self::assertContains('2', array_slice($words, $alpha));
        self::assertContains('3', array_slice($words, $beta));

        $qdf = PdfInspector::qdf($pdf);
        self::assertStringContainsString('papercut-toc-0', $qdf);
        self::assertStringContainsString('papercut-toc-1', $qdf);
        self::assertSame(2, self::destinationPage($qdf, 'papercut-toc-0'));
        self::assertSame(3, self::destinationPage($qdf, 'papercut-toc-1'));
        self::assertSame(2, preg_match_all('~/Subtype /Link~', $qdf));
        self::assertSame(0, preg_match_all('~/URI~', $qdf));
    }

    public function test_bookmarks_off_keeps_numbers_and_links(): void
    {
        $pdf = self::document()->toPdf(new PdfOptions(bookmarks: false))->bytes();

        $words = self::words($pdf, 1);
        self::assertContains('2', $words);
        self::assertContains('3', $words);
        $qdf = PdfInspector::qdf($pdf);
        self::assertSame(2, preg_match_all('~/Subtype /Link~', $qdf));
        self::assertStringNotContainsString('/Outlines', $qdf);
    }

    public function test_two_tables_each_show_their_headings_pages(): void
    {
        $pdf = self::document(Toc::make()->levels(1, 1)->title('Main'), Toc::make()->levels(3, 3)->title('Details'))->toPdf()->bytes();

        $words = self::words($pdf, 1);
        $detailsAt = array_search('Details', $words, true);
        self::assertNotFalse($detailsAt);
        $first = array_slice($words, 0, $detailsAt);
        $second = array_slice($words, $detailsAt);
        self::assertContains('2', $first);
        self::assertContains('3', $first);
        self::assertContains('3', $second);
        self::assertNotContains('2', $second);
    }
}
