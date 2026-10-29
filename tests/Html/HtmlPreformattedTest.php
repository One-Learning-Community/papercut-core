<?php

declare(strict_types=1);

namespace Papercut\Tests\Html;

use Papercut\{Document, Page, Text, View};
use Papercut\Export\HtmlOptions;
use Papercut\Style\{Style, Theme, WhiteSpace};
use Papercut\Markup\Markup;
use PHPUnit\Framework\TestCase;

final class HtmlPreformattedTest extends TestCase
{
    private function html(string $markup): string
    {
        return Document::make(Page::a4()->body(Markup::html($markup)))->toHtml()->bytes();
    }

    public function test_html_pre_wrap_css(): void
    {
        $html = $this->html("<pre>a  b\nc</pre>");
        self::assertMatchesRegularExpression('~<pre style="[^"]*white-space:pre-wrap~', $html);
        self::assertStringContainsString('tab-size:4', $html);
        self::assertStringContainsString('a  b<br>c', $html);
    }

    public function test_html_pre_element(): void
    {
        $html = $this->html('<pre>x</pre><p>run <code>y</code> now</p>');
        self::assertStringContainsString('<pre ', $html);
        self::assertStringContainsString('</pre>', $html);
        self::assertMatchesRegularExpression('~<code style="[^"]*">y</code>~', $html);
        self::assertSame(1, substr_count($html, '<pre '));
    }

    public function test_plain_text_is_not_pre(): void
    {
        $html = $this->html('<p>one  two</p>');
        self::assertStringNotContainsString('<pre', $html);
        self::assertStringNotContainsString('white-space', $html);
    }

    public function test_pre_inside_a_pre_wrap_container_still_declares_pre_wrap(): void
    {
        $node = View::make(Markup::html('<pre>aaaa  bbbb</pre>'))->apply(Style::make(whiteSpace: WhiteSpace::PreWrap));
        $html = Document::make(Page::a4()->body($node))->toHtml()->bytes();
        self::assertMatchesRegularExpression('~<pre style="[^"]*white-space:pre-wrap~', $html);
    }

    public function test_pre_restyled_to_normal_collapses_in_html(): void
    {
        $theme = Theme::default()->withStyle('pre', Style::make(whiteSpace: WhiteSpace::Normal));
        $html = Document::make(Page::a4()->body(Markup::html('<pre>a  b</pre>')))->toHtml(new HtmlOptions(theme: $theme))->bytes();
        self::assertMatchesRegularExpression('~<pre style="[^"]*white-space:normal~', $html);
    }

    public function test_any_pre_wrap_element_gets_the_pdf_tab_width(): void
    {
        $node = Text::make("a\tb")->apply(Style::make(whiteSpace: WhiteSpace::PreWrap));
        $html = Document::make(Page::a4()->body($node))->toHtml()->bytes();
        self::assertMatchesRegularExpression('~<p style="[^"]*white-space:pre-wrap[^"]*"~', $html);
        self::assertMatchesRegularExpression('~<p style="[^"]*tab-size:4~', $html);
    }
}
