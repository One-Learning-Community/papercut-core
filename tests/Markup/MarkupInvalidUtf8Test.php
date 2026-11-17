<?php

declare(strict_types=1);

namespace Quoin\Tests\Markup;

use Quoin\Export\{HtmlOptions, PdfOptions};
use Quoin\Markup\{Markup, MarkupOptions, StyleMode};
use Quoin\Tests\Support\FailsOnDiagnostics;
use Quoin\{Document, Page, Text};
use PHPUnit\Framework\TestCase;

/** Pasted markup with invalid UTF-8 is cleaned with one note, like builder text (D2, promise 3). */
final class MarkupInvalidUtf8Test extends TestCase
{
    use FailsOnDiagnostics;

    private const NOTE = 'invalid UTF-8 replaced with U+FFFD';

    public function test_markup_html_notes_the_replacement_once_in_pdf_and_html(): void
    {
        foreach ([StyleMode::Theme, StyleMode::Original] as $mode) {
            $doc = Document::make(Page::a4()->body(Markup::html("<p>\xff\xfe bad \xc3</p><p>again \xff</p>", new MarkupOptions(styles: $mode))));
            $notes = [];
            $doc->toPdf(new PdfOptions(onWarning: static function (string $m) use (&$notes): void {
                $notes[] = $m;
            }, cacheDir: false));
            self::assertCount(1, $notes, implode("\n", $notes));
            self::assertStringContainsString(self::NOTE, $notes[0]);
            self::assertStringContainsString("\u{FFFD}", $doc->toHtml(new HtmlOptions())->bytes());
            $html = [];
            $doc->toHtml(new HtmlOptions(onWarning: static function (string $m) use (&$html): void {
                $html[] = $m;
            }));
            self::assertCount(1, array_filter($html, static fn (string $m): bool => str_contains($m, self::NOTE)));
        }
    }

    public function test_valid_markup_has_no_utf8_note(): void
    {
        $notes = [];
        Document::make(Page::a4()->body(Markup::html('<p>café — ok</p>')))->toPdf(new PdfOptions(onWarning: static function (string $m) use (&$notes): void {
            $notes[] = $m;
        }, cacheDir: false));
        self::assertSame([], $notes);
    }

    public function test_inline_markup_text_notes_it_too(): void
    {
        $t = Text::markup("a <b>b\xff</b>");
        self::assertCount(1, $t->notes());
        self::assertSame(self::NOTE, $t->notes()[0]->feature);
    }
}
