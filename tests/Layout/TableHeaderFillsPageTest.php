<?php

declare(strict_types=1);

namespace Quoin\Tests\Layout;

use Quoin\{Document, Image, Page, QrCode, Table};
use Quoin\Export\PdfOptions;
use Quoin\Markup\{Markup, MarkupOptions, StyleMode};
use Quoin\Tests\Support\FailsOnDiagnostics;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A header taller than the page leaves a picture cell no room; it must behave like a text cell (header alone, with notes). */
final class TableHeaderFillsPageTest extends TestCase
{
    use FailsOnDiagnostics;

    private const GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    /** @return list<string> */
    private function notes(Document $doc): array
    {
        $notes = [];
        $doc->toPdf(new PdfOptions(onWarning: static function (string $m) use (&$notes): void {
            $notes[] = $m;
        }, cacheDir: false));
        return $notes;
    }

    /** @param list<string> $notes */
    private function assertHeaderNotes(array $notes): void
    {
        self::assertNotEmpty(array_filter($notes, static fn (string $n): bool => str_contains($n, 'Table header leaves no room')), implode("\n", $notes));
    }

    /** @return iterable<string, array{StyleMode}> */
    public static function modes(): iterable
    {
        yield 'theme' => [StyleMode::Theme];
        yield 'original' => [StyleMode::Original];
    }

    #[DataProvider('modes')]
    public function test_markup_table_with_image_body_cell(StyleMode $mode): void
    {
        $html = '<table><tr><th>' . str_repeat('word ', 1200) . '</th></tr><tr><td><img src=b></td></tr></table>';
        $body = Markup::html($html, new MarkupOptions(styles: $mode, images: static fn (string $s): string => base64_decode(self::GIF)));
        $this->assertHeaderNotes($this->notes(Document::make(Page::a4()->body($body))));
    }

    public function test_builder_table_with_image_and_qr_body_cells(): void
    {
        $header = str_repeat('word ', 1200);
        $im = imagecreatetruecolor(40, 20);
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        foreach ([Image::bytes($png), QrCode::make('hello')] as $cell) {
            $t = Table::make()->head($header)->row($cell);
            $this->assertHeaderNotes($this->notes(Document::make(Page::a4()->body($t))));
        }
    }
}
