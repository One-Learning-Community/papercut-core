<?php

declare(strict_types=1);

namespace Papercut\Tests\Node;

use Papercut\{Document, Page, Span, Text};
use PHPUnit\Framework\TestCase;

final class InlineCodeTest extends TestCase
{
    public function test_span_code_is_a_literal_span_in_the_code_style(): void
    {
        $s = Span::code('toPdf()');
        self::assertSame('toPdf()', $s->text);
        self::assertSame('code', $s->styleName);
        self::assertNull($s->href);
        self::assertFalse($s->lineBreak);
    }

    public function test_text_code_is_one_code_span(): void
    {
        self::assertEquals(Text::spans(Span::code('/v2/shipments'))->spanList(), Text::code('/v2/shipments')->spanList());
        self::assertCount(1, Text::code('x')->spanList());
    }

    public function test_the_text_stays_literal(): void
    {
        self::assertSame('a < b && "c"', Text::code('a < b && "c"')->spanList()[0]->text);
    }

    public function test_append_takes_a_code_span(): void
    {
        $t = Text::make('Call ')->append(Span::code('toPdf()'), ' to export.');
        self::assertSame(['Call ', 'toPdf()', ' to export.'], array_map(static fn (Span $s): string => $s->text, $t->spanList()));
        self::assertSame([null, 'code', null], array_map(static fn (Span $s): ?string => $s->styleName, $t->spanList()));
    }

    public function test_html_matches_the_markup_code_path(): void
    {
        $viaApi = Text::make('Call ')->append(Span::code('a < b'), ' now.');
        $viaMarkup = Text::markup('Call <code>' . htmlspecialchars('a < b') . '</code> now.');
        self::assertSame(self::html($viaMarkup), self::html($viaApi));
        self::assertSame(self::html(Text::markup('<code>x</code>')), self::html(Text::code('x')));
    }

    public function test_pdf_matches_the_markup_code_path(): void
    {
        $a = Document::make(Page::a4()->body(Text::make('Call ')->append(Span::code('toPdf()'), ' now.')))->toPdf()->bytes();
        $b = Document::make(Page::a4()->body(Text::markup('Call <code>toPdf()</code> now.')))->toPdf()->bytes();
        self::assertSame(self::normalizePdf($b), self::normalizePdf($a));
    }

    public function test_bold_code_matches_markup_in_pdf(): void
    {
        $a = Document::make(Page::a4()->body(Text::code('x')->bold()))->toPdf()->bytes();
        $b = Document::make(Page::a4()->body(Text::markup('<b><code>x</code></b>')))->toPdf()->bytes();
        self::assertSame(self::normalizePdf($b), self::normalizePdf($a));
    }

    private static function html(Text $t): string
    {
        return Document::make(Page::a4()->body($t))->toHtml()->bytes();
    }

    private static function normalizePdf(string $pdf): string
    {
        return (string) preg_replace('/\/(CreationDate|ModDate|ID)\s*(\(.*?\)|\[.*?\])/s', '', $pdf);
    }
}
