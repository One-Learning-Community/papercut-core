<?php

declare(strict_types=1);

namespace Papercut\Tests\Export;

use Papercut\{Document, Equation, Link, Page, Span, Table, Text};
use Papercut\Exception\UnsupportedFeature;
use Papercut\Export\{HtmlOptions, PdfOptions};
use Papercut\Markup\Markup;
use Papercut\Math\Math;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MathNotesReportTest extends TestCase
{
    private const TABLE = '<math><mtable rowlines="solid"><mtr><mtd><mi>a</mi></mtd></mtr></mtable></math>';

    /** @return list<string> */
    private function pdfMessages(Document $doc): array
    {
        $messages = [];
        $doc->toPdf(new PdfOptions(onWarning: function (string $m) use (&$messages): void {
            $messages[] = $m;
        }, cacheDir: false));
        return $messages;
    }

    public function test_block_equation_from_mathml_reports_table(): void
    {
        $messages = $this->pdfMessages(Document::make(Page::a4()->body(Equation::of(Math::mathml(self::TABLE)))));
        self::assertCount(1, $messages);
        self::assertStringContainsString('math <mtable>', $messages[0]);
    }

    public function test_nested_in_builder_fraction(): void
    {
        $messages = $this->pdfMessages(Document::make(Page::a4()->body(Equation::of(Math::frac(Math::mathml(self::TABLE), 'x')))));
        self::assertCount(1, $messages);
        self::assertStringContainsString('math <mtable>', $messages[0]);
    }

    public function test_inline_span(): void
    {
        $text = Text::spans(Span::text('a '), Span::math(Math::mathml('<math><menclose notation="box"><mi>x</mi></menclose></math>')));
        $messages = $this->pdfMessages(Document::make(Page::a4()->body($text)));
        self::assertCount(1, $messages);
        self::assertStringContainsString('math <menclose>', $messages[0]);
    }

    public function test_strict_mode_fails_on_dropped_only(): void
    {
        $dropped = Document::make(Page::a4()->body(Equation::of(Math::mathml('<math><mi>a</mi><img src="x.png"/></math>'))));
        try {
            $dropped->toPdf(new PdfOptions(strict: true, cacheDir: false));
            self::fail('Expected UnsupportedFeature');
        } catch (UnsupportedFeature $e) {
            self::assertStringContainsString('math <img>', $e->getMessage());
        }

        $approx = Document::make(Page::a4()->body(Equation::of(Math::mathml(self::TABLE))));
        $messages = [];
        $approx->toPdf(new PdfOptions(strict: true, cacheDir: false, onWarning: function (string $m) use (&$messages): void {
            $messages[] = $m;
        }));
        self::assertCount(1, $messages);
        self::assertStringContainsString('math <mtable>', $messages[0]);
    }

    /** @return iterable<string, array{Document, non-empty-string}> */
    public static function containers(): iterable
    {
        $table = static fn (): Equation => Equation::of(Math::mathml(self::TABLE));
        yield 'table cell' => [Document::make(Page::a4()->body(Markup::html('<table><tr><td>' . self::TABLE . '</td></tr></table>'))), '> td[0] > text[0] > math > mtable[0]'];
        yield 'list item' => [Document::make(Page::a4()->body(Markup::html('<ul><li>' . self::TABLE . '</li></ul>'))), '> item[0] > text[0] > math > mtable[0]'];
        yield 'link' => [Document::make(Page::a4()->body(Markup::html('<p><a href="https://e.test/">' . self::TABLE . '</a></p>'))), '> text[0] > math > mtable[0]'];
        yield 'link around equation' => [Document::make(Page::a4()->body(Link::to('https://e.test/', $table()))), '> link[0] > equation[0] > math > mtable[0]'];
        yield 'header' => [Document::make(Page::a4()->header($table())->body(Text::make('x'))), 'page > header > equation[0] > math > mtable[0]'];
        yield 'table row' => [Document::make(Page::a4()->body(Table::make()->columns('auto')->row($table()))), '> td[0] > equation[0] > math > mtable[0]'];
    }

    /** @param non-empty-string $suffix */
    #[DataProvider('containers')]
    public function test_one_note_with_its_path_wherever_the_formula_sits(Document $doc, string $suffix): void
    {
        $messages = $this->pdfMessages($doc);
        self::assertCount(1, $messages);
        self::assertStringEndsWith($suffix, $messages[0]);
    }

    public function test_html_export_reports_too(): void
    {
        $messages = [];
        Document::make(Page::a4()->body(Equation::of(Math::mathml(self::TABLE))))
            ->toHtml(new HtmlOptions(onWarning: function (string $m) use (&$messages): void {
                $messages[] = $m;
            }));
        $math = array_values(array_filter($messages, static fn (string $m): bool => str_contains($m, 'math <mtable>')));
        self::assertCount(1, $math);
        self::assertStringStartsWith('html: ', $math[0]);
    }
}
