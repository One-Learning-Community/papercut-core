<?php

declare(strict_types=1);

namespace Papercut\Tests\Feature;

use Papercut\{Column, Document, Page, PageNumber, PdfPage, Place, QrCode, Text, View};
use Papercut\Style\Anchor;
use Papercut\Exception\InvalidValue;
use Papercut\Export\{FidelityReport, HtmlOptions};
use Papercut\Tests\Support\{PdfInspector, Raster};
use Papercut\Value\Percent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use function Papercut\{mm, pt};

/**
 * Page::overlay(): content placed at a point on the page, over everything else. The page is
 * 400 x 200pt with no margins, so at 72 dpi a point is a pixel and 25% across is x = 100.
 */
final class OverlayTest extends TestCase
{
    protected function setUp(): void
    {
        $reason = Raster::unavailable();
        if ($reason !== null) {
            self::markTestSkipped($reason);
        }
    }

    private static function page(): Page
    {
        return Page::size(pt(400), pt(200))->margin(pt(0));
    }

    private static function square(float $side = 20, string $color = '#000'): View
    {
        return Column::make()->width(pt($side))->height(pt($side))->background($color);
    }

    private static function pdf(Page $page): string
    {
        $pdf = Document::make($page)->toPdf()->bytes();
        PdfInspector::check($pdf);

        return $pdf;
    }

    /** @return iterable<string, array{Anchor, float, float}> anchor => the square's top-left corner, for a point at (100, 100) */
    public static function anchors(): iterable
    {
        yield 'top left' => [Anchor::TopLeft, 100, 100];
        yield 'top' => [Anchor::Top, 90, 100];
        yield 'top right' => [Anchor::TopRight, 80, 100];
        yield 'left' => [Anchor::Left, 100, 90];
        yield 'center' => [Anchor::Center, 90, 90];
        yield 'right' => [Anchor::Right, 80, 90];
        yield 'bottom left' => [Anchor::BottomLeft, 100, 80];
        yield 'bottom' => [Anchor::Bottom, 90, 80];
        yield 'bottom right' => [Anchor::BottomRight, 80, 80];
    }

    #[DataProvider('anchors')]
    public function test_the_anchor_is_the_point_of_the_content_placed_at_x_y(Anchor $anchor, float $left, float $top): void
    {
        $image = Raster::of(self::pdf(self::page()->overlay(Place::at('25%', '50%', self::square())->anchor($anchor))));

        self::assertTrue(Raster::ink($image, (int) $left + 1, (int) $top + 1), 'top-left corner inked');
        self::assertTrue(Raster::ink($image, (int) $left + 18, (int) $top + 18), 'bottom-right corner inked');
        self::assertFalse(Raster::ink($image, (int) $left - 2, (int) $top + 10), 'paper to the left');
        self::assertFalse(Raster::ink($image, (int) $left + 22, (int) $top + 10), 'paper to the right');
        self::assertFalse(Raster::ink($image, (int) $left + 10, (int) $top - 2), 'paper above');
        self::assertFalse(Raster::ink($image, (int) $left + 10, (int) $top + 22), 'paper below');
    }

    public function test_the_default_anchor_is_the_top_left_corner(): void
    {
        $image = Raster::of(self::pdf(self::page()->overlay(Place::at(pt(100), mm(10), self::square()))));

        self::assertTrue(Raster::ink($image, 101, 30));
        self::assertFalse(Raster::ink($image, 98, 30));
        self::assertFalse(Raster::ink($image, 110, 27), 'mm(10) is 28.35pt down');
    }

    public function test_a_width_in_percent_is_of_the_page_and_content_percentages_are_of_the_place(): void
    {
        $bar = Column::make()->width(Percent::of(100))->height(pt(10))->background('#000');
        $image = Raster::of(self::pdf(self::page()->overlay(Place::at('50%', '0', $bar)->width('10%')->anchor(Anchor::Top))));

        self::assertTrue(Raster::ink($image, 181, 5));
        self::assertTrue(Raster::ink($image, 219, 5));
        self::assertFalse(Raster::ink($image, 178, 5), '40pt wide, centred on x = 200');
        self::assertFalse(Raster::ink($image, 222, 5));
    }

    public function test_a_qr_code_fills_a_place_given_a_width(): void
    {
        $page = self::page()->overlay(Place::at('50%', '50%', QrCode::make('https://example.com')->width(Percent::of(100)))->width('20%')->anchor(Anchor::Center));
        $image = Raster::of(self::pdf($page));

        // 80pt square centred on (200, 100); the first module of the finder pattern sits just inside the quiet zone.
        $inked = 0;
        for ($x = 160; $x < 240; $x++) {
            $inked += Raster::ink($image, $x, 100) ? 1 : 0;
        }
        self::assertGreaterThan(10, $inked);
        self::assertFalse(Raster::ink($image, 158, 100));
        self::assertFalse(Raster::ink($image, 242, 100));
        self::assertFalse(Raster::ink($image, 200, 58));
    }

    public function test_text_without_a_width_is_as_wide_as_its_words(): void
    {
        $pdf = self::pdf(self::page()->overlay(Place::at('50%', '50%', Text::make('Ada Lovelace')->size(pt(20)))->anchor(Anchor::Center)));

        $words = PdfInspector::words($pdf);
        self::assertSame(['Ada', 'Lovelace'], array_column($words, 'text'));
        $centre = ($words[0]['xMin'] + $words[1]['xMax']) / 2;
        self::assertEqualsWithDelta(200.0, $centre, 1.0, 'the line is centred on x');
        self::assertEqualsWithDelta(100.0, ($words[0]['yMin'] + $words[0]['yMax']) / 2, 4.0, 'and on y');
    }

    public function test_text_wider_than_the_page_wraps_at_the_page_width(): void
    {
        $long = str_repeat('word ', 200);
        $pdf = self::pdf(self::page()->overlay(Place::at(pt(0), pt(0), Text::make($long))));

        foreach (PdfInspector::words($pdf) as $w) {
            self::assertLessThanOrEqual(400.0, $w['xMax']);
        }
    }

    public function test_the_overlay_is_drawn_over_the_body_and_the_template(): void
    {
        $template = Document::make(self::page()->body(Column::make()->width(pt(400))->height(pt(200))->background('#000')))->toPdf()->bytes();
        $page = Page::template(PdfPage::bytes($template))->margin(pt(0))
            ->body(self::square(200))
            ->overlay(Place::at(pt(50), pt(50), self::square(20, '#fff')), Place::at(pt(300), pt(50), self::square(20, '#fff')));
        $image = Raster::of(self::pdf($page));

        self::assertFalse(Raster::ink($image, 60, 60), 'over the body');
        self::assertFalse(Raster::ink($image, 310, 60), 'over the template');
        self::assertTrue(Raster::ink($image, 80, 80));
        self::assertTrue(Raster::ink($image, 330, 80));
    }

    public function test_the_overlay_repeats_on_every_page_with_its_page_number(): void
    {
        $body = array_map(static fn (int $i): View => self::square(80), range(1, 4));
        $page = self::page()->body(...$body)->overlay(Place::at('100%', '100%', PageNumber::make('Page {page}'))->anchor(Anchor::BottomRight));
        $pdf = self::pdf($page);

        self::assertSame('2', PdfInspector::info($pdf)['Pages'] ?? null);
        self::assertSame(['Page', '1'], array_column(PdfInspector::words($pdf, 1), 'text'));
        self::assertSame(['Page', '2'], array_column(PdfInspector::words($pdf, 2), 'text'));
    }

    public function test_a_place_outside_an_overlay_is_refused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('A place only works in a page overlay');

        Document::make(self::page()->body(Place::at('0', '0', 'x')))->toPdf();
    }

    public function test_html_refuses_a_place_outside_an_overlay_too(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('A place only works in a page overlay; add it with Page::overlay(), not inside other content (at page > overlay > place[0] > place[0])');

        Document::make(self::page()->overlay(Place::at('0', '0', Place::at('0', '0', 'x'))))->toHtml();
    }

    public function test_a_length_without_a_unit_is_refused(): void
    {
        $this->expectException(InvalidValue::class);
        $this->expectExceptionMessage('"12pt"');

        Place::at('12', '50%', 'x');
    }

    public function test_a_width_that_is_not_a_length_is_refused(): void
    {
        $this->expectException(InvalidValue::class);

        Place::at('0', '0', 'x')->width('wide');
    }

    public function test_html_positions_each_place_on_the_page(): void
    {
        $messages = [];
        $html = Document::make(self::page()->body(Text::make('Body'))->overlay(Place::at('50%', '42%', Text::make('Ada'))->anchor(Anchor::Top)->width('30%')))
            ->toHtml(new HtmlOptions(onWarning: function (string $m, ?FidelityReport $r = null) use (&$messages): void {
                $messages[] = $m;
            }))->bytes();

        self::assertContains('html: approximated overlay positions at page', $messages);
        self::assertMatchesRegularExpression('/<section class="pc-page" style="[^"]*position:relative/', $html);
        self::assertMatchesRegularExpression('/<div class="pc-overlay"[^>]*><div style="position:absolute;left:50%;top:42%;width:30%;transform:translate\(-50%,0\)"[^>]*>.*Ada/s', $html);
    }
}
