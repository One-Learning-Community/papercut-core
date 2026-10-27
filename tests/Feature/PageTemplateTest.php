<?php

declare(strict_types=1);

namespace Papercut\Tests\Feature;

use Papercut\{Column, Document, Page, PdfPage, Text};
use Papercut\Exception\PdfUnreadable;
use Papercut\Export\{FidelityReport, HtmlOptions};
use Papercut\Tests\Support\{PdfInspector, Raster};
use PHPUnit\Framework\TestCase;
use function Papercut\pt;

/** Page::template(): a page the size of another PDF's page, with that page drawn behind it. */
final class PageTemplateTest extends TestCase
{
    protected function setUp(): void
    {
        $reason = Raster::unavailable();
        if ($reason !== null) {
            self::markTestSkipped($reason);
        }
    }

    /** A 400 x 300pt page with a 50pt black square in its top-left corner. */
    private static function template(): string
    {
        return Document::make(Page::size(pt(400), pt(300))->margin(pt(0))->body(
            Column::make()->width(pt(50))->height(pt(50))->background('#000'),
        ))->toPdf()->bytes();
    }

    public function test_takes_the_size_of_the_template_page(): void
    {
        $page = Page::template(PdfPage::bytes(self::template()));

        self::assertEqualsWithDelta(400.0, $page->width(), 1e-6);
        self::assertEqualsWithDelta(300.0, $page->height(), 1e-6);
    }

    public function test_draws_the_template_behind_the_body(): void
    {
        $pdf = Document::make(Page::template(PdfPage::bytes(self::template()))->margin(pt(100))->body(Text::make('Ada Lovelace')))->toPdf()->bytes();
        PdfInspector::check($pdf);

        self::assertSame('400 x 300 pts', PdfInspector::info($pdf)['Page size'] ?? null);
        self::assertStringContainsString('Ada Lovelace', PdfInspector::text($pdf));
        $image = Raster::of($pdf);
        self::assertTrue(Raster::ink($image, 25, 25), 'the template fills the page from its corner');
        self::assertFalse(Raster::ink($image, 60, 25));
    }

    public function test_more_background_is_drawn_over_the_template(): void
    {
        $page = Page::template(PdfPage::bytes(self::template()))->background(Column::make()->height(pt(30))->background('#fff'));
        $image = Raster::of(Document::make($page->body(Text::make('x')))->toPdf()->bytes());

        self::assertFalse(Raster::ink($image, 25, 15), 'white band over the square');
        self::assertTrue(Raster::ink($image, 25, 40), 'the square below the band');
        self::assertCount(1, $page->backgroundNodes());
        self::assertNotNull($page->templatePage());
    }

    public function test_a_missing_template_is_reported_when_the_page_is_made(): void
    {
        $this->expectException(PdfUnreadable::class);
        $this->expectExceptionMessage('Cannot read PDF "/no/such/certificate.pdf"');

        Page::template(PdfPage::file('/no/such/certificate.pdf'));
    }

    public function test_html_leaves_the_template_out_and_says_so(): void
    {
        $messages = [];
        $html = Document::make(Page::template(PdfPage::bytes(self::template()))->body(Text::make('Ada Lovelace')))
            ->toHtml(new HtmlOptions(onWarning: function (string $m, ?FidelityReport $r = null) use (&$messages): void {
                $messages[] = $m;
            }))->bytes();

        self::assertContains('html: dropped PDF page template at page', $messages);
        self::assertStringContainsString('Ada Lovelace', $html);
    }
}
