<?php

declare(strict_types=1);

namespace Quoin\Tests\Text;

use Quoin\{Document, Equation, Page, Text};
use Quoin\Export\{FontMode, HtmlOptions, PdfOptions};
use Quoin\Math\Math;
use Quoin\Tests\Support\PdfInspector;
use Quoin\Text\FontSet;
use PHPUnit\Framework\TestCase;

/** A CFF (OpenType/PostScript) math font, Fira Math, set as the equation font. The font file lives in the math package's test fixtures. */
final class CffMathFontTest extends TestCase
{
    private function fira(): string
    {
        $path = __DIR__ . '/../../vendor/quoin/math/tests/fixtures/FiraMath-Regular.otf';
        if (!is_file($path)) {
            $this->markTestSkipped('the math package test fixtures are not installed (dist archive)');
        }

        return $path;
    }

    private function document(): Document
    {
        return Document::make(Page::a4()->body(
            Text::make('Roots'),
            Equation::of(Math::tex('\sum_{i=1}^{n} \sqrt{x^2 + 1} = \int_0^1 \frac{a}{b}\, dx')),
        ))->createdAt(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
    }

    public function test_a_pdf_with_equations_in_a_cff_math_font(): void
    {
        $fonts = (new FontSet())->withMathFont($this->fira());
        $pdf = $this->document()->toPdf(new PdfOptions(fonts: $fonts))->bytes();
        $bundled = $this->document()->toPdf()->bytes();

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('/FontFile3', $pdf); // the CFF font program, embedded whole
        $this->assertGreaterThan(strlen($bundled), strlen($pdf));
        PdfInspector::check($pdf);
    }

    public function test_an_html_export_with_equations_in_a_cff_math_font(): void
    {
        $fonts = (new FontSet())->withMathFont($this->fira());
        $html = $this->document()->toHtml(new HtmlOptions(fonts: $fonts, fontMode: FontMode::Embed))->bytes();

        $this->assertStringContainsString('<math', $html);
        $this->assertMatchesRegularExpression('/@font-face\{font-family:"Quoin Math";src:url\(data:font\/otf;base64,[A-Za-z0-9+\/=]+\) format\("opentype"\)/', $html);
        $this->assertMatchesRegularExpression('/@font-face\{font-family:"Inter";src:url\(data:font\/ttf;base64,[A-Za-z0-9+\/=]+\) format\("truetype"\)/', $html);
    }
}
