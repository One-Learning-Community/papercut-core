<?php

declare(strict_types=1);

namespace Papercut\Tests\Decorator;

use Papercut\{Document, OverflowMode, Page};
use Papercut\Decorator\CodeBlock;
use Papercut\Exception\UnsupportedFeature;
use Papercut\Export\{PdfExporter, PdfOptions};
use Papercut\Layout\{LayoutContext, LayoutEngine, Paginator};
use Papercut\Paint\{Painter, Rect};
use Papercut\Style\{PageThemes, Theme, ThemeOverride};
use Papercut\Tests\Support\FakeBackend;
use Papercut\Text\FontRegistry;
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

final class CodeBlockThemeTest extends TestCase
{
    private const string DARK = '#24292e';
    private const string LIGHT = '#f6f8fa';

    private static function page(?ThemeOverride $theme = null): Page
    {
        $page = Page::size(pt(300), pt(220))->margin(pt(10))->headerGap(pt(0))->footerGap(pt(0));
        return $theme === null ? $page : $page->theme($theme);
    }

    /** @return list<list<string>> the rectangle fills on each page */
    private static function fills(Document $doc): array
    {
        $b = new FakeBackend();
        $themes = PageThemes::for(Theme::default(), $doc);
        $ctx = new LayoutContext(FontRegistry::withDefaults($b), $themes->document(), $b, OverflowMode::Throw, static function (string $w): void {});
        $ctx->themes = $themes;
        $pages = Painter::paint((new Paginator(new LayoutEngine($ctx), $ctx))->paginate($doc));
        $out = [];
        foreach ($pages as $page) {
            $fills = [];
            foreach ($page->ops as $op) {
                if ($op instanceof Rect && $op->fill !== null) {
                    $fills[] = (string) $op->fill;
                }
            }
            $out[] = $fills;
        }
        return $out;
    }

    private static function block(): CodeBlock
    {
        return CodeBlock::make('<?php echo 1;')->language('php');
    }

    public function test_page_code_theme_colours_blocks_without_their_own(): void
    {
        $plain = self::fills(Document::make(self::page()->body(self::block())));
        $dark = self::fills(Document::make(self::page(new ThemeOverride(codeTheme: 'github-dark'))->body(self::block())));
        self::assertContains(self::LIGHT, $plain[0]);
        self::assertNotContains(self::DARK, $plain[0]);
        self::assertContains(self::DARK, $dark[0]);
        self::assertNotContains(self::LIGHT, $dark[0]);
    }

    public function test_document_code_theme_applies_to_its_pages(): void
    {
        $doc = Document::make(self::page()->body(self::block()))->theme(new ThemeOverride(codeTheme: 'github-dark'));
        self::assertContains(self::DARK, self::fills($doc)[0]);
    }

    public function test_a_blocks_own_theme_wins_over_the_page(): void
    {
        $page = self::page(new ThemeOverride(codeTheme: 'github-dark'))->body(self::block()->theme('papercut-light'));
        $fills = self::fills(Document::make($page))[0];
        self::assertContains(self::LIGHT, $fills);
        self::assertNotContains(self::DARK, $fills);
    }

    public function test_unknown_page_code_theme_falls_back_with_the_usual_warning(): void
    {
        $doc = Document::make(self::page(new ThemeOverride(codeTheme: 'nope'))->body(self::block()));
        self::assertContains(self::LIGHT, self::fills($doc)[0]);
        try {
            (new PdfExporter())->export($doc, new PdfOptions(strict: true));
            self::fail('Expected a strict-mode failure.');
        } catch (UnsupportedFeature $e) {
            self::assertStringContainsString("unknown theme 'nope'", $e->getMessage());
        }
    }

    public function test_same_code_block_on_two_pages_gets_each_pages_theme(): void
    {
        $block = self::block();
        $doc = Document::make(
            self::page()->body($block),
            self::page(new ThemeOverride(codeTheme: 'github-dark'))->body($block),
            self::page()->body($block),
        );
        $fills = self::fills($doc);
        self::assertContains(self::LIGHT, $fills[0]);
        self::assertContains(self::DARK, $fills[1]);
        self::assertNotContains(self::DARK, $fills[2]);
        self::assertContains(self::LIGHT, $fills[2]);
    }

    public function test_markup_code_blocks_take_the_page_code_theme(): void
    {
        $html = '<pre><code class="language-php">&lt;?php echo 1;</code></pre>';
        $dark = self::fills(Document::make(self::page(new ThemeOverride(codeTheme: 'github-dark'))->body(\Papercut\Markup\Markup::html($html))));
        $plain = self::fills(Document::make(self::page()->body(\Papercut\Markup\Markup::html($html))));
        self::assertContains(self::DARK, $dark[0]);
        self::assertNotContains(self::LIGHT, $dark[0]);
        self::assertContains(self::LIGHT, $plain[0]);
    }

    public function test_render_without_a_theme_is_unchanged(): void
    {
        self::assertSame(self::LIGHT, (string) self::block()->render()->style()->background);
        self::assertSame(self::DARK, (string) self::block()->renderFor(Theme::default()->withOverride(new ThemeOverride(codeTheme: 'github-dark')))->style()->background);
    }
}
