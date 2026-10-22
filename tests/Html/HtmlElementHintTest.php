<?php

declare(strict_types=1);

namespace Papercut\Tests\Html;

use Papercut\{Document, Page, View};
use Papercut\Exception\InvalidValue;
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

final class HtmlElementHintTest extends TestCase
{
    private function html(View $v): string
    {
        return Document::make(Page::a4()->body($v))->toHtml()->bytes();
    }

    private function openingTag(string $html, string $tag): string
    {
        self::assertSame(1, preg_match('~<' . $tag . '\b[^>]*>~', $html, $m), "no <$tag> in output");
        return $m[0];
    }

    public function test_default_is_div(): void
    {
        $v = View::make('x')->padding(pt(4));
        self::assertSame('div', $v->htmlElement());
        $tag = $this->openingTag($this->html($v), 'div style="padding');
        self::assertStringNotContainsString('margin:0', $tag);
    }

    public function test_aside_with_margin_reset(): void
    {
        $html = $this->html(View::make('x')->htmlTag('aside')->padding(pt(4)));
        $tag = $this->openingTag($html, 'aside');
        self::assertStringStartsWith('<aside', $tag);
        self::assertSame(1, substr_count($tag, 'margin:0'));
        self::assertStringContainsString('padding', $tag);
        self::assertStringContainsString('</aside>', $html);
    }

    public function test_own_margin_wins_over_reset(): void
    {
        $tag = $this->openingTag($this->html(View::make('x')->htmlTag('aside')->margin(pt(6))), 'aside');
        self::assertStringNotContainsString('margin:0', $tag);
        self::assertSame(1, substr_count($tag, 'margin:'));
        self::assertMatchesRegularExpression('~margin:6(pt|\.0+pt)?~', $tag);
    }

    public function test_hint_survives_children_and_style_modifiers(): void
    {
        $v = View::make('x')->htmlTag('section')->withChildren('y')->padding(pt(1));
        self::assertSame('section', $v->htmlElement());
    }

    public function test_unknown_tag_suggests(): void
    {
        try {
            View::make('x')->htmlTag('asdie');
            self::fail('expected InvalidValue');
        } catch (InvalidValue $e) {
            self::assertSame('aside', $e->suggestion);
        }
        $this->expectException(InvalidValue::class);
        View::make('x')->htmlTag('script');
    }

    public function test_pdf_ignores_hint(): void
    {
        $plain = Document::make(Page::a4()->body(View::make('x')->padding(pt(4))))->toPdf()->bytes();
        $hinted = Document::make(Page::a4()->body(View::make('x')->htmlTag('section')->padding(pt(4))))->toPdf()->bytes();
        self::assertSame($plain, $hinted);
    }
}
