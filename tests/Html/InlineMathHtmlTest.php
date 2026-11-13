<?php

declare(strict_types=1);

namespace Papercut\Tests\Html;

use Papercut\{Document, Page, Span, Table, Text};
use Papercut\Math\Math;
use PHPUnit\Framework\TestCase;

final class InlineMathHtmlTest extends TestCase
{
    private function html(\Papercut\Node $n): string
    {
        return Document::make(Page::a4()->body($n))->toHtml()->bytes();
    }

    public function test_inline_math_element(): void
    {
        $html = $this->html(Text::spans(Span::text('before '), Span::math(Math::frac('a', 'b')), Span::text(' after')));
        self::assertMatchesRegularExpression('~<p[^>]*>before <math [^>]*display="inline"[^>]*>.*</math> after</p>~s', $html);
        self::assertStringNotContainsString('display="block"', $html);
        self::assertStringContainsString('.papercut math{', $html);
        self::assertStringContainsString('Papercut Math', $html);
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

    public function test_no_math_no_font_rule(): void
    {
        self::assertStringNotContainsString('Papercut Math', $this->html(Text::make('plain')));
    }
}
