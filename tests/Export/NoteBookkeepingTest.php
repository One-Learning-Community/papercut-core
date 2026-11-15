<?php

declare(strict_types=1);

namespace Papercut\Tests\Export;

use Papercut\{Document, Page, PageBreak, Row, Svg, Text};
use Papercut\Exception\UnsupportedFeature;
use Papercut\Export\{FidelityReport, HtmlExporter, HtmlOptions, PdfOptions};
use PHPUnit\Framework\TestCase;

/** Each fidelity note appears once, at the node it belongs to, in every format that has it. */
final class NoteBookkeepingTest extends TestCase
{
    protected function setUp(): void
    {
        set_error_handler(static function (int $no, string $msg): never {
            throw new \ErrorException($msg, 0, $no);
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
    }

    /** @return list<string> */
    private static function pdf(Document $doc): array
    {
        $m = [];
        $doc->toPdf(new PdfOptions(onWarning: function (string $x, ?FidelityReport $r = null) use (&$m): void {
            $m[] = $x;
        }));

        return $m;
    }

    public function test_missing_glyphs_are_one_dropped_note_at_the_node(): void
    {
        $doc = Document::make(Page::a4()->body(Text::p('ひらがな x'), Text::p('ok')));
        self::assertSame(['pdf: dropped missing glyphs in font family Inter ("ひらがな") at page > body > text[0]'], self::pdf($doc));
    }

    public function test_missing_glyphs_are_a_report_object(): void
    {
        $reports = [];
        Document::make(Page::a4()->body(Text::p('日本')))->toPdf(new PdfOptions(onWarning: function (string $m, ?FidelityReport $r = null) use (&$reports): void {
            $reports[] = $r;
        }));
        self::assertCount(1, $reports);
        self::assertInstanceOf(FidelityReport::class, $reports[0]);
        self::assertSame(\Papercut\Export\FidelityLevel::Dropped, $reports[0]->level);
    }

    public function test_the_same_text_in_two_nodes_is_noted_at_each(): void
    {
        $doc = Document::make(Page::a4()->body(Text::p('日本'), Text::p('日本')));
        self::assertSame([
            'pdf: dropped missing glyphs in font family Inter ("日本") at page > body > text[0]',
            'pdf: dropped missing glyphs in font family Inter ("日本") at page > body > text[1]',
        ], self::pdf($doc));
    }

    public function test_replacement_character_reports_nothing(): void
    {
        self::assertSame([], self::pdf(Document::make(Page::a4()->body(Text::p("a\u{FFFD}b")))));
    }

    public function test_strict_pdf_throws_for_missing_glyphs(): void
    {
        $this->expectException(UnsupportedFeature::class);
        Document::make(Page::a4()->body(Text::p('日本')))->toPdf(new PdfOptions(strict: true));
    }

    public function test_html_reports_no_missing_glyphs(): void
    {
        $m = [];
        Document::make(Page::a4()->body(Text::p('日本')))->export(new HtmlExporter(), new HtmlOptions(onWarning: function (string $x) use (&$m): void {
            $m[] = $x;
        }));
        self::assertSame([], array_values(array_filter($m, static fn (string $x): bool => str_contains($x, 'glyph'))));
    }

    public function test_page_break_in_a_row_is_one_note_in_pdf_and_html(): void
    {
        $doc = Document::make(Page::a4()->body(Row::make(Text::p('A'), PageBreak::make(), Text::p('B'))));
        self::assertSame(['pdf: approximated page break inside a row at page > body > row[0] > page-break[1]'], self::pdf($doc));
        $m = [];
        $doc->export(new HtmlExporter(), new HtmlOptions(onWarning: function (string $x) use (&$m): void {
            $m[] = $x;
        }));
        // Browsers ignore break-before inside a flex row, so the HTML reports it too.
        self::assertSame(['html: approximated page break inside a row at page > body > row[0] > page-break[1]'], array_values(array_filter($m, static fn (string $x): bool => str_contains($x, 'page break'))));
    }

    public function test_svg_text_missing_glyphs_list_the_characters(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 20"><text x="0" y="15">日本</text></svg>';
        $notes = self::pdf(Document::make(Page::a4()->body(Svg::fromString($svg))));
        self::assertCount(1, $notes);
        self::assertStringContainsString('missing glyphs in SVG text', $notes[0]);
        self::assertStringContainsString('("日本")', $notes[0]);
        self::assertStringEndsWith('at page > body > svg[0]', $notes[0]);
    }

    public function test_page_break_in_a_column_is_not_noted(): void
    {
        $doc = Document::make(Page::a4()->body(Text::p('A'), PageBreak::make(), Text::p('B')));
        self::assertSame([], self::pdf($doc));
    }
}
