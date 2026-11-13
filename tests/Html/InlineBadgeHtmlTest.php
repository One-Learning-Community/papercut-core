<?php

declare(strict_types=1);

namespace Papercut\Tests\Html;

use Papercut\{Document, Page, Span, Text};
use Papercut\Style\Style;
use Papercut\Value\Color;
use PHPUnit\Framework\TestCase;

final class InlineBadgeHtmlTest extends TestCase
{
    private function html(Span ...$spans): string
    {
        return Document::make(Page::a4()->body(Text::spans(Span::text('Returns '), ...$spans)))->toHtml()->bytes();
    }

    private function badgeStyle(string $html, string $label): string
    {
        self::assertSame(1, preg_match('/<span class="pc-badge" style="([^"]*)">' . $label . '<\/span>/', $html, $m), $html);

        return $m[1];
    }

    public function test_inline_badge_is_a_pc_badge_span(): void
    {
        $html = $this->html(Span::badge('201', 'tip'));
        self::assertStringContainsString('<span class="pc-badge" style="', $html);
        $style = $this->badgeStyle($html, '201');
        self::assertStringContainsString('padding:1.5pt 6pt', $style);
        self::assertStringContainsString('border-radius:8pt', $style);
        self::assertStringContainsString('background:#d1fae5', $style);
        self::assertStringContainsString('color:#065f46', $style);
        self::assertStringContainsString('font-weight:700', $style);
        self::assertStringContainsString('white-space:nowrap', $style);
    }

    public function test_outline_badge_has_a_border_and_transparent_background(): void
    {
        $html = $this->html(Span::badge('Beta', 'note', 'outline'));
        $style = $this->badgeStyle($html, 'Beta');
        self::assertStringContainsString('border-width:0.75pt', $style);
        self::assertStringContainsString('border-color:#7dd3fc', $style);
        self::assertStringContainsString('background:rgba(0, 0, 0, 0)', $style);
    }

    public function test_badge_in_a_link_stays_inside_the_link(): void
    {
        $badge = new Span('201', Style::none(), 'https://example.test/', false, 'badge badge.tip');
        $html = $this->html($badge);
        self::assertMatchesRegularExpression('/<a href="https:\/\/example.test\/"><span class="pc-badge" style="[^"]*">201<\/span><\/a>/', $html);
    }

    public function test_plain_spans_are_not_badges(): void
    {
        self::assertStringNotContainsString('pc-badge', $this->html(Span::text('x')));
    }
}
