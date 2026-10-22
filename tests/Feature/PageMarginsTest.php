<?php

declare(strict_types=1);

namespace Papercut\Tests\Feature;

use Papercut\{Document, OverflowMode, Page, Text};
use Papercut\Exception\InvalidValue;
use Papercut\Export\PdfOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

/** Margins that meet or cross leave no room for content, so both formats refuse the page. */
final class PageMarginsTest extends TestCase
{
    /** @return iterable<string, array{\Closure(Document): mixed}> */
    public static function formats(): iterable
    {
        yield 'pdf' => [static fn (Document $d) => $d->toPdf()];
        yield 'pdf, clip mode' => [static fn (Document $d) => $d->toPdf(new PdfOptions(overflow: OverflowMode::Clip))];
        yield 'html' => [static fn (Document $d) => $d->toHtml()];
    }

    private static function doc(Page ...$pages): Document
    {
        return Document::make(...array_map(static fn (Page $p) => $p->body(Text::make('Hello world')), $pages));
    }

    /** @param \Closure(Document): mixed $export */
    private static function assertRefused(\Closure $export, Document $doc, string $message): void
    {
        try {
            $export($doc);
            self::fail('rendered');
        } catch (InvalidValue $e) {
            self::assertSame('page margins', $e->kind);
            self::assertSame($message, $e->getMessage());
        }
    }

    /** @param \Closure(Document): mixed $export */
    #[DataProvider('formats')]
    public function test_left_and_right_margins_wider_than_the_page(\Closure $export): void
    {
        self::assertRefused($export, self::doc(Page::size(pt(200), pt(300))->margin(pt(10), pt(90), pt(10), pt(120))),
            'Invalid page margins "120pt left + 90pt right" on "page": they leave no room for content on a page 200pt wide.');
    }

    /** @param \Closure(Document): mixed $export */
    #[DataProvider('formats')]
    public function test_margins_that_exactly_meet_leave_no_room(\Closure $export): void
    {
        self::assertRefused($export, self::doc(Page::size(pt(200), pt(300))->margin(pt(10), pt(100))),
            'Invalid page margins "100pt left + 100pt right" on "page": they leave no room for content on a page 200pt wide.');
    }

    /** @param \Closure(Document): mixed $export */
    #[DataProvider('formats')]
    public function test_top_and_bottom_margins_taller_than_the_page(\Closure $export): void
    {
        self::assertRefused($export, self::doc(Page::size(pt(200), pt(300))->margin(pt(200), pt(10), pt(150), pt(10))),
            'Invalid page margins "200pt top + 150pt bottom" on "page": they leave no room for content on a page 300pt tall.');
    }

    /** @param \Closure(Document): mixed $export */
    #[DataProvider('formats')]
    public function test_landscape_is_checked_after_the_turn(\Closure $export): void
    {
        // 240pt of top and bottom margin fit the 400pt-tall upright page, not the 200pt-tall turned one.
        $page = Page::size(pt(200), pt(400))->margin(pt(120), pt(10));
        $export(self::doc($page));
        self::assertRefused($export, self::doc($page->landscape()),
            'Invalid page margins "120pt top + 120pt bottom" on "page": they leave no room for content on a page 200pt tall.');
    }

    /** @param \Closure(Document): mixed $export */
    #[DataProvider('formats')]
    public function test_names_the_page_among_several(\Closure $export): void
    {
        self::assertRefused($export, self::doc(Page::size(pt(200), pt(300)), Page::size(pt(200), pt(300))->margin(pt(10), pt(150))),
            'Invalid page margins "150pt left + 150pt right" on "page[1]": they leave no room for content on a page 200pt wide.');
    }

    /** @param \Closure(Document): mixed $export */
    #[DataProvider('formats')]
    public function test_a_narrow_column_of_content_still_renders(\Closure $export): void
    {
        $export(self::doc(Page::size(pt(200), pt(300))->margin(pt(10), pt(95))));
        $this->addToAssertionCount(1);
    }
}
