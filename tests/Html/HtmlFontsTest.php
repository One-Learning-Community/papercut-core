<?php

declare(strict_types=1);

namespace Papercut\Tests\Html;

use Papercut\{Document, Page, Span, Text};
use Papercut\Exception\FontNotFound;
use Papercut\Export\{FontMode, HtmlOptions};
use Papercut\Style\Style;
use Papercut\Text\{FontFace, FontSet, FontStyle};
use PHPUnit\Framework\TestCase;

final class HtmlFontsTest extends TestCase
{
    private function doc(?string $family = null): Document
    {
        $text = Text::make('Hello');
        return Document::make(Page::a4()->body($family === null ? $text : $text->font($family)));
    }

    public function test_none_emits_family_stack_only(): void
    {
        $html = $this->doc()->toHtml()->bytes();
        self::assertStringNotContainsString('@font-face', $html);
        self::assertStringContainsString('font-family:&quot;Inter&quot;, system-ui, sans-serif', $html);
    }

    public function test_embed_emits_only_used_families(): void
    {
        $extra = new FontFace('Other', __DIR__ . '/../../resources/fonts/Inter/Inter-Regular.ttf');
        $o = new HtmlOptions(fonts: new FontSet([$extra]), fontMode: FontMode::Embed);
        $html = $this->doc()->toHtml($o)->bytes();
        self::assertSame(4, substr_count($html, '@font-face'));
        self::assertStringNotContainsString('"Other"', $html);
        self::assertStringContainsString('@font-face{font-family:"Inter";src:url(data:font/ttf;base64,', $html);
        self::assertStringContainsString('format("truetype");font-weight:700;font-style:italic}', $html);
        self::assertLessThan(strpos($html, '.papercut{'), strpos($html, '@font-face'));
    }

    public function test_embed_includes_a_used_custom_family_and_last_registration_wins(): void
    {
        $dir = __DIR__ . '/../../resources/fonts/Inter';
        $set = new FontSet([new FontFace('Inter', "$dir/Inter-Bold.ttf", 400), new FontFace('Mine', "$dir/Inter-Bold.ttf", 400)]);
        $o = new HtmlOptions(fonts: $set, fontMode: FontMode::Embed);
        $html = $this->doc('Mine')->toHtml($o)->bytes();
        self::assertSame(1, substr_count($html, 'font-family:"Mine"'));
        self::assertSame(4, substr_count($html, 'font-family:"Inter"'));
        self::assertSame(1, preg_match('/font-family:"Inter";src:url\(data:font\/ttf;base64,([^)]+)\) format\("truetype"\);font-weight:400;font-style:normal/', $html, $m));
        self::assertSame(base64_encode((string) file_get_contents("$dir/Inter-Bold.ttf")), $m[1] ?? null);
    }

    public function test_embed_includes_fallback_families(): void
    {
        $face = new FontFace('Fall', __DIR__ . '/../../resources/fonts/Inter/Inter-Regular.ttf');
        $o = new HtmlOptions(fonts: (new FontSet([$face]))->withFallbacks('Fall'), fontMode: FontMode::Embed);
        $html = $this->doc()->toHtml($o)->bytes();
        self::assertSame(1, substr_count($html, 'font-family:"Fall";src:url(data:font/ttf;base64,'));
    }

    public function test_fallback_that_is_also_used_gives_no_duplicate_font_face(): void
    {
        $face = new FontFace('Fall', __DIR__ . '/../../resources/fonts/Inter/Inter-Regular.ttf');
        $o = new HtmlOptions(fonts: (new FontSet([$face]))->withFallbacks('Fall'), fontMode: FontMode::Embed);
        $html = $this->doc('Fall')->toHtml($o)->bytes();
        self::assertSame(1, substr_count($html, 'font-family:"Fall";src:'));
    }

    public function test_link_mode_emits_the_fallback_face_url(): void
    {
        $face = new FontFace('Fall', '/fonts/fall.ttf');
        $o = new HtmlOptions(fonts: (new FontSet([$face]))->withFallbacks('Fall'), fontMode: FontMode::Link, fontUrl: static fn (FontFace $f): ?string => $f->family === 'Fall' ? '/u/fall.ttf' : null);
        $html = $this->doc()->toHtml($o)->bytes();
        self::assertStringContainsString('font-family:"Fall";src:url("/u/fall.ttf")', $html);
    }

    public function test_embed_with_missing_file_throws(): void
    {
        $o = new HtmlOptions(fonts: new FontSet([new FontFace('Gone', '/nope/gone.ttf')]), fontMode: FontMode::Embed);
        $this->expectException(FontNotFound::class);
        $this->expectExceptionMessage('Font file not found: /nope/gone.ttf');
        $this->doc('Gone')->toHtml($o);
    }

    public function test_link_uses_callback_and_skips_null(): void
    {
        $o = new HtmlOptions(fontMode: FontMode::Link, fontUrl: static fn (FontFace $f): ?string => $f->style === FontStyle::Italic ? null : '/f/a"b.ttf?w=' . $f->weight);
        $html = $this->doc()->toHtml($o)->bytes();
        self::assertSame(2, substr_count($html, '@font-face'));
        self::assertStringContainsString('src:url("/f/a\\"b.ttf?w=400") format("truetype")', $html);
        self::assertStringNotContainsString('base64', $html);
    }

    public function test_link_without_callback_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('FontMode::Link needs a fontUrl callback');
        $this->doc()->toHtml(new HtmlOptions(fontMode: FontMode::Link));
    }

    public function test_fallback_families_join_the_stack(): void
    {
        $o = new HtmlOptions(fonts: (new FontSet())->withFallbacks('Noto Sans', 'Arial'));
        $doc = Document::make(Page::a4()->body(Text::spans(new Span('y', Style::make(fontFamily: 'Georgia')))));
        $html = $doc->toHtml($o)->bytes();
        self::assertStringContainsString('&quot;Inter&quot;, &quot;Noto Sans&quot;, &quot;Arial&quot;, system-ui, sans-serif', $html);
        self::assertStringContainsString('&quot;Georgia&quot;, &quot;Noto Sans&quot;, &quot;Arial&quot;, system-ui, sans-serif', $html);
    }
}
