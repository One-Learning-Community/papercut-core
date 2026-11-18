<?php

declare(strict_types=1);

namespace Quoin\Tests\Export;

use Quoin\{Document, Equation, Page, Span, Text};
use Quoin\Export\PdfOptions;
use Quoin\Markup\Markup;
use Phactorial\Math;
use PHPUnit\Framework\TestCase;

final class MatrixEquationTest extends TestCase
{
    /** @return array{string, list<string>} the PDF bytes and the warnings reported while writing them */
    private function render(Document $doc): array
    {
        $messages = [];
        $pdf = $doc->toPdf(new PdfOptions(onWarning: function (string $m) use (&$messages): void {
            $messages[] = $m;
        }, cacheDir: false))->bytes();
        return [$pdf, $messages];
    }

    public function test_matrix_equation_renders_in_pdf(): void
    {
        $doc = Document::make(Page::a4()->body(
            Equation::of(Math::matrix([['a', 'b'], ['c', 'd']])),
            Text::spans(Span::text('det '), Span::math(Math::matrix([['a', 'b'], ['c', 'd']], 'bar'))),
        ));
        [$pdf, $messages] = $this->render($doc);
        self::assertStringStartsWith('%PDF', $pdf);
        self::assertSame([], $messages);
    }

    public function test_markup_mtable_reports_no_note(): void
    {
        $table = '<math display="block"><mtable><mtr><mtd><mi>a</mi></mtd><mtd><mi>b</mi></mtd></mtr></mtable></math>';
        $doc = Document::make(Page::a4()->body(Markup::html($table)));
        self::assertSame([], $this->render($doc)[1]);
    }

    public function test_markup_mtable_with_ignored_attribute_reports_one_note(): void
    {
        $table = '<math display="block"><mtable rowlines="solid"><mtr><mtd><mi>a</mi></mtd></mtr></mtable></math>';
        [, $messages] = $this->render(Document::make(Page::a4()->body(Markup::html($table))));
        self::assertCount(1, $messages, implode("\n", $messages));
        self::assertStringContainsString('math <mtable> (rowlines is not drawn)', $messages[0]);
    }
}
