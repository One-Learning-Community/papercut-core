<?php

declare(strict_types=1);

namespace Papercut\Tests\Feature;

use Papercut\{Column, Document, Page, PdfPage, Text};
use Papercut\Exception\{InvalidValue, PdfUnreadable};
use Papercut\Export\{Encryption, FidelityReport, HtmlOptions, PdfOptions};
use Papercut\Tests\Support\{FakeBackend, PdfInspector, Raster};
use PHPUnit\Framework\TestCase;
use function Papercut\pt;

/**
 * Pages of existing PDFs drawn into new ones. The templates are made by Papercut: a 200 x 100pt
 * page with a 50pt black square in its top-left corner and one word below it.
 */
final class PdfPageTest extends TestCase
{
    protected function setUp(): void
    {
        $reason = Raster::unavailable();
        exec('command -v pdftotext 2>/dev/null', $out, $code);
        if ($reason !== null || $code !== 0) {
            self::markTestSkipped($reason ?? 'pdftotext (poppler) is not installed.');
        }
    }

    private static function templatePage(string $word): Page
    {
        return Page::size(pt(200), pt(100))->margin(pt(0))->body(
            Column::make()->width(pt(50))->height(pt(50))->background('#000'),
            Text::make($word),
        );
    }

    private static function template(string $word = 'Template', ?Encryption $encryption = null): string
    {
        return Document::make(self::templatePage($word))->toPdf(new PdfOptions(encryption: $encryption))->bytes();
    }

    private static function pdf(PdfPage $pdfPage, ?PdfOptions $options = null): string
    {
        return Document::make(Page::size(pt(300), pt(200))->margin(pt(0))->body($pdfPage))->toPdf($options)->bytes();
    }

    private static function textOf(string $pdf, string $password = ''): string
    {
        $file = tempnam(sys_get_temp_dir(), 'papercut-pdfpage-');
        file_put_contents($file, $pdf);
        try {
            exec(sprintf('pdftotext %s %s - 2>&1', $password === '' ? '' : '-upw ' . escapeshellarg($password), escapeshellarg($file)), $out, $code);
            self::assertSame(0, $code, implode("\n", $out));
            return implode("\n", $out);
        } finally {
            unlink($file);
        }
    }

    public function test_draws_the_page_at_its_natural_size(): void
    {
        $pdf = self::pdf(PdfPage::bytes(self::template()));
        PdfInspector::check($pdf);
        $image = Raster::of($pdf);

        self::assertTrue(Raster::ink($image, 25, 25), 'the square is drawn');
        self::assertTrue(Raster::ink($image, 48, 48));
        self::assertFalse(Raster::ink($image, 55, 25), 'and is 50pt wide');
        self::assertFalse(Raster::ink($image, 25, 55), 'and 50pt tall');
        self::assertStringContainsString('Template', self::textOf($pdf), 'its text stays text');
    }

    public function test_a_rotated_page_is_drawn_upright(): void
    {
        $dir = sys_get_temp_dir() . '/papercut-rotate-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            file_put_contents("$dir/in.pdf", self::template());
            exec(sprintf('qpdf --rotate=+90:1 %s %s 2>&1', escapeshellarg("$dir/in.pdf"), escapeshellarg("$dir/out.pdf")), $out, $code);
            self::assertSame(0, $code, implode("\n", $out));
            $page = Page::template(PdfPage::file("$dir/out.pdf"))->margin(pt(0));
            $image = Raster::of(Document::make($page->body(Text::make('x')))->toPdf()->bytes());
        } finally {
            array_map('unlink', glob("$dir/*") ?: []);
            rmdir($dir);
        }

        // Turned a quarter clockwise, the 200 x 100 page stands 100 x 200 and the square moves to the top-right corner.
        self::assertEqualsWithDelta(100.0, $page->width(), 1e-6);
        self::assertEqualsWithDelta(200.0, $page->height(), 1e-6);
        self::assertTrue(Raster::ink($image, 75, 25));
        self::assertFalse(Raster::ink($image, 25, 25));
    }

    public function test_scales_to_the_width_it_is_given(): void
    {
        $image = Raster::of(self::pdf(PdfPage::bytes(self::template())->width(pt(100))));

        self::assertTrue(Raster::ink($image, 12, 12));
        self::assertFalse(Raster::ink($image, 30, 12), 'half size: the square is 25pt wide');
        self::assertFalse(Raster::ink($image, 12, 30));
    }

    public function test_reads_a_file(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'papercut-template-');
        file_put_contents($file, self::template('FromFile'));
        try {
            self::assertStringContainsString('FromFile', self::textOf(self::pdf(PdfPage::file($file))));
        } finally {
            unlink($file);
        }
    }

    public function test_picks_the_page_to_draw(): void
    {
        $template = Document::make(self::templatePage('First'), self::templatePage('Second'))->toPdf()->bytes();

        $text = self::textOf(self::pdf(PdfPage::bytes($template, page: 2)));

        self::assertStringContainsString('Second', $text);
        self::assertStringNotContainsString('First', $text);
    }

    public function test_a_page_number_below_one_is_refused(): void
    {
        $this->expectException(InvalidValue::class);
        $this->expectExceptionMessage('PDF page number "0"');

        PdfPage::bytes(self::template(), page: 0);
    }

    public function test_a_missing_page_is_reported(): void
    {
        $this->expectException(PdfUnreadable::class);
        $this->expectExceptionMessage('Cannot draw page 3 of the PDF: it has 1 page');

        self::pdf(PdfPage::bytes(self::template(), page: 3));
    }

    public function test_bytes_that_are_not_a_pdf_are_reported(): void
    {
        $this->expectException(PdfUnreadable::class);
        $this->expectExceptionMessage('Cannot read PDF:');

        self::pdf(PdfPage::bytes('not a pdf at all'));
    }

    public function test_a_missing_file_is_reported(): void
    {
        $this->expectException(PdfUnreadable::class);
        $this->expectExceptionMessage('Cannot read PDF "/no/such/template.pdf": file not found or not readable');

        self::pdf(PdfPage::file('/no/such/template.pdf'));
    }

    public function test_opens_a_protected_template_with_its_password(): void
    {
        $template = self::template('Locked', Encryption::password('open-sesame'));

        self::assertStringContainsString('Locked', self::textOf(self::pdf(PdfPage::bytes($template, password: 'open-sesame'))));
    }

    public function test_a_protected_template_without_its_password_is_reported(): void
    {
        $this->expectException(PdfUnreadable::class);
        $this->expectExceptionMessage('Cannot read PDF:');

        self::pdf(PdfPage::bytes(self::template('Locked', Encryption::password('open-sesame'))));
    }

    public function test_an_encrypted_document_encrypts_the_template_too(): void
    {
        $pdf = self::pdf(PdfPage::bytes(self::template('Secret')), new PdfOptions(encryption: Encryption::password('pw')));

        self::assertStringNotContainsString('Secret', $pdf);
        self::assertStringContainsString('Secret', self::textOf($pdf, 'pw'));
        $image = Raster::of($pdf, 'pw');
        self::assertTrue(Raster::ink($image, 25, 25));
    }

    public function test_a_page_drawn_many_times_is_stored_once(): void
    {
        $template = self::template();
        $page = static fn (): Page => Page::size(pt(300), pt(200))->margin(pt(0))->body(PdfPage::bytes($template));
        $once = Document::make($page())->toPdf()->bytes();
        $thrice = Document::make($page(), $page(), $page())->toPdf()->bytes();

        self::assertSame(1, substr_count(PdfInspector::qdf($thrice), '/Subtype /Form'));
        self::assertLessThan(strlen($once) * 1.5, strlen($thrice));
    }

    public function test_a_backend_that_cannot_draw_pdf_pages_says_so(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cannot draw pages of other PDFs');

        self::pdf(PdfPage::bytes(self::template()), new PdfOptions(backend: new FakeBackend()));
    }

    public function test_html_leaves_the_page_out_and_says_so(): void
    {
        $messages = [];
        $html = Document::make(Page::a4()->body(PdfPage::bytes(self::template()), Text::make('After')))
            ->toHtml(new HtmlOptions(onWarning: function (string $m, ?FidelityReport $r = null) use (&$messages): void {
                $messages[] = $m;
            }))->bytes();

        self::assertContains('html: dropped PDF page at page > body > pdf-page[0]', $messages);
        self::assertStringContainsString('After', $html);
        self::assertStringNotContainsString('%PDF', $html);
    }
}
