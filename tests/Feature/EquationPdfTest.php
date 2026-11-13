<?php

declare(strict_types=1);

namespace Papercut\Tests\Feature;

use Papercut\{Document, Equation, Page, Text};
use Papercut\Math\Math;
use Papercut\Paint\DrawSvg;
use Papercut\Tests\Support\{LayoutHarness, PdfInspector};
use PHPUnit\Framework\TestCase;

final class EquationPdfTest extends TestCase
{
    private static function render(Equation ...$equations): string
    {
        return Document::make(Page::a4()->body(Text::make('Before'), ...$equations))->toPdf()->bytes();
    }

    public function test_pdf_text_is_selectable(): void
    {
        $pdf = self::render(Equation::of('x =', Math::frac(Math::row('−b ±', Math::sqrt(Math::sup('b', '2'), '− 4ac')), '2a')));
        PdfInspector::check($pdf);
        $text = PdfInspector::text($pdf);
        $this->assertStringContainsString('𝑥', $text);
        $this->assertStringContainsString('𝑏', $text);
    }

    public function test_stretched_fence_drawn_as_svg(): void
    {
        $h = new LayoutHarness();
        $f = $h->layout(Equation::of(Math::sup(Math::fenced('(', ')', Math::frac('a', 'b')), '2')), 300)->fragment;
        $this->assertNotNull($f);
        $found = false;
        $walk = function ($fr) use (&$walk, &$found): void {
            foreach ($fr->ops() as $op) {
                if ($op instanceof DrawSvg) {
                    $found = true;
                    // php-pdf ignores a view box's origin, so the paths must sit in a box that starts at zero.
                    $this->assertMatchesRegularExpression('/viewBox="0 0 /', $op->svg->xml);
                }
            }
            foreach ($fr->children() as $c) {
                $walk($c['fragment']);
            }
        };
        $walk($f);
        $this->assertTrue($found);
    }
}
