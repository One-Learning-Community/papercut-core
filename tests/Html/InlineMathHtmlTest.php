<?php

declare(strict_types=1);

namespace Quoin\Tests\Html;

use Quoin\{Document, Page, Span, Table, Text};
use Phactorial\Math;
use PHPUnit\Framework\TestCase;

final class InlineMathHtmlTest extends TestCase
{
    private function html(\Quoin\Node $n): string
    {
        return Document::make(Page::a4()->body($n))->toHtml()->bytes();
    }

    public function test_inline_math_element(): void
    {
        $html = $this->html(Text::spans(Span::text('before '), Span::math(Math::frac('a', 'b')), Span::text(' after')));
        self::assertMatchesRegularExpression('~<p[^>]*>before <math [^>]*display="inline"[^>]*>.*</math> after</p>~s', $html);
        self::assertStringNotContainsString('display="block"', $html);
        self::assertStringContainsString('.quoin math{', $html);
        self::assertStringContainsString('Quoin Math', $html);
    }

    public function test_inline_math_escapes_specials(): void
    {
        $html = $this->html(Text::spans(Span::math(Math::text('a < b & c'))));
        self::assertStringContainsString('a &lt; b &amp; c', $html);
    }

    public function test_inline_in_table_cell(): void
    {
        $html = $this->html(Table::make()->columns('auto')->row(Text::spans(Span::text('v '), Span::math(Math::sup('x', '2')))));
        self::assertMatchesRegularExpression('~<td[^>]*>.*v <math [^>]*display="inline"~s', $html);
    }

    public function test_math_wrapper_has_only_color_size_and_background(): void
    {
        $style = \Quoin\Style\Style::make(underline: true, strike: true, italic: true, fontWeight: 700, letterSpacing: \Quoin\px(2), color: \Quoin\Value\Color::hex('#cc0000'), background: \Quoin\Value\Color::hex('#eeeeee'));
        $html = $this->html(Text::spans(new Span('', $style, math: Math::frac('a', 'b'))));
        self::assertStringContainsString('color:', $html);
        self::assertDoesNotMatchRegularExpression('~<span style="[^"]*(font-style|font-weight|text-decoration|letter-spacing)[^"]*"><math~', $html);
        self::assertMatchesRegularExpression('~<span style="[^"]*color:[^"]*"><math~', $html);
        self::assertMatchesRegularExpression('~<span style="[^"]*background-color:#eeeeee[^"]*"><math~', $html);
    }

    public function test_no_math_no_font_rule(): void
    {
        self::assertStringNotContainsString('Quoin Math', $this->html(Text::make('plain')));
    }
}
