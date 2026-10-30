<?php

declare(strict_types=1);

namespace Papercut\Tests\Decorator;

use Papercut\{Document, Page, Text, View};
use Papercut\Code\{CodeHighlighting, CodeToken, HighlightedCode, Highlighter, UnknownLanguage, UnknownTheme};
use Papercut\Decorator\CodeBlock;
use Papercut\Exception\InvalidVariant;
use Papercut\Exception\UnsupportedFeature;
use Papercut\Export\{FidelityLevel, PdfExporter, PdfOptions};
use Papercut\Markup\Note;
use Papercut\Tests\Support\LayoutHarness;
use Papercut\Value\Color;
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

final class CodeBlockTest extends TestCase
{
    /** @return list<string> */
    private static function spanColours(Text $text): array
    {
        $out = [];
        foreach ($text->spanList() as $span) {
            if ($span->style->color !== null) {
                $out[] = (string) $span->style->color;
            }
        }
        return $out;
    }

    private static function textAt(View $view, int $i): Text
    {
        $child = $view->children()[$i];
        self::assertInstanceOf(Text::class, $child);
        return $child;
    }

    private static function boxAt(View $view, int $i): View
    {
        $child = $view->children()[$i];
        self::assertInstanceOf(View::class, $child);
        return $child;
    }

    private static function words(Text $text): string
    {
        return $text->spanList()[0]->text;
    }

    /** @return list<\Papercut\Paint\PageDisplayList> */
    private static function paginate(CodeBlock $block, float $w = 300, float $h = 220): array
    {
        $page = Page::size(pt($w), pt($h))->margin(pt(10))->headerGap(pt(0))->footerGap(pt(0));
        $b = new \Papercut\Tests\Support\FakeBackend();
        $ctx = new \Papercut\Layout\LayoutContext(\Papercut\Text\FontRegistry::withDefaults($b), \Papercut\Style\Theme::default(), $b, \Papercut\OverflowMode::Throw, static function (string $w): void {});
        return \Papercut\Paint\Painter::paint((new \Papercut\Layout\Paginator(new \Papercut\Layout\LayoutEngine($ctx), $ctx))->paginate(Document::make($page->body($block))));
    }

    private static function dropped(string $feature): Note
    {
        return new Note(FidelityLevel::Dropped, $feature);
    }

    /** @return array<string, string> run text => font id */
    private static function fontIds(\Papercut\Node|\Papercut\Component $node): array
    {
        $page = Page::size(pt(300), pt(220))->margin(pt(10))->headerGap(pt(0))->footerGap(pt(0));
        $b = new \Papercut\Tests\Support\FakeBackend();
        $ctx = new \Papercut\Layout\LayoutContext(\Papercut\Text\FontRegistry::withDefaults($b), \Papercut\Style\Theme::default(), $b, \Papercut\OverflowMode::Throw, static function (string $w): void {});
        $pages = \Papercut\Paint\Painter::paint((new \Papercut\Layout\Paginator(new \Papercut\Layout\LayoutEngine($ctx), $ctx))->paginate(Document::make($page->body($node))));
        $ids = [];
        foreach ($pages[0]->ops as $o) {
            if ($o instanceof \Papercut\Paint\TextRun) {
                $ids[trim($o->text)] = $o->font->id();
            }
        }
        return $ids;
    }

    public function test_code_in_a_blockquote_is_not_italic_except_theme_italics(): void
    {
        $block = CodeBlock::make("<?php\n// hi\necho 1;")->language('php');
        $plain = self::fontIds($block);
        $quoted = self::fontIds(\Papercut\Decorator\Blockquote::make($block));
        self::assertArrayHasKey('echo', $quoted);
        self::assertSame($plain['echo'], $quoted['echo']);
        self::assertSame($plain['// hi'], $quoted['// hi']);
        self::assertNotSame($quoted['echo'], $quoted['// hi']);
    }

    public function test_trailing_newlines_are_the_same_on_the_plain_and_highlighted_paths(): void
    {
        foreach (["a\n\n" => 2, "a\n" => 1, 'a' => 1, "a\r\n\r\n" => 2] as $code => $lines) {
            foreach (['php', 'text'] as $language) {
                $view = CodeBlock::make($code)->language($language)->render();
                $text = self::textAt($view, count($view->children()) - 1);
                $breaks = count(array_filter($text->spanList(), static fn (\Papercut\Span $s): bool => $s->lineBreak));
                self::assertSame($lines - 1, $breaks, json_encode($code) . " as $language");
            }
        }
    }

    public function test_renders_coloured_spans_and_a_label_line(): void
    {
        $view = CodeBlock::make("<?php\n// hi")->language('php')->render();
        $label = self::textAt($view, 0);
        $code = self::textAt($view, 1);
        self::assertSame('PHP', self::words($label));
        self::assertSame('#94a3b8', (string) $label->style()->color);
        self::assertSame('#f6f8fa', (string) $view->style()->background);
        self::assertContains('#8b949e', self::spanColours($code));
        self::assertSame([], $view->notes());
    }

    public function test_render_highlights_once(): void
    {
        $double = new class implements Highlighter {
            public int $calls = 0;

            public function highlight(string $code, string $language, string $theme): HighlightedCode
            {
                $this->calls++;
                return new HighlightedCode([[new CodeToken($code)]], Color::hex('#ffffff'), Color::hex('#000000'));
            }

            public function displayName(string $language): string
            {
                return 'Double';
            }
        };
        $block = CodeBlock::make('x')->language('any')->highlighting(new CodeHighlighting($double));
        $block->render();
        $block->render();
        self::assertSame(1, $double->calls);
        $block->language('other')->render();
        self::assertSame(2, $double->calls, 'a changed copy highlights afresh');
    }

    public function test_label_override_and_off(): void
    {
        $view = CodeBlock::make('{}')->language('json')->label('composer.json')->render();
        self::assertSame('composer.json', self::words(self::textAt($view, 0)));

        $off = CodeBlock::make('{}')->language('json')->label(false)->render();
        self::assertCount(1, $off->children());
    }

    public function test_plain_text_words_and_nohighlight(): void
    {
        $text = CodeBlock::make('a b')->language('text')->render();
        self::assertSame('TEXT', self::words(self::textAt($text, 0)));
        self::assertSame([], self::spanColours(self::textAt($text, 1)));
        self::assertSame([], $text->notes());

        $none = CodeBlock::make('a b')->language('nohighlight')->render();
        self::assertCount(1, $none->children());
        self::assertSame([], self::spanColours(self::textAt($none, 0)));
        self::assertSame([], $none->notes());
    }

    public function test_unknown_language_falls_back_with_a_dropped_note(): void
    {
        $view = CodeBlock::make('abc')->language('phpp')->render();
        self::assertSame('PHPP', self::words(self::textAt($view, 0)));
        self::assertSame([], self::spanColours(self::textAt($view, 1)));
        self::assertEquals([self::dropped("syntax highlighting (unknown language 'phpp')")], $view->notes());
    }

    public function test_unknown_theme_uses_the_default_with_a_dropped_note(): void
    {
        $view = CodeBlock::make('<?php echo 1;')->language('php')->theme('nope')->render();
        self::assertSame('#f6f8fa', (string) $view->style()->background);
        self::assertEquals([self::dropped("syntax highlighting (unknown theme 'nope'; the default theme was used)")], $view->notes());
        self::assertNotSame([], self::spanColours(self::textAt($view, 1)));
    }

    private static function withoutHighlighter(): CodeBlock
    {
        return CodeBlock::make('echo 1;')->language('php')->highlighting(new CodeHighlighting(false));
    }

    public function test_without_a_highlighter_renders_plain_with_an_install_note(): void
    {
        $view = self::withoutHighlighter()->render();
        self::assertSame('PHP', self::words(self::textAt($view, 0)));
        self::assertSame([], self::spanColours(self::textAt($view, 1)));
        self::assertSame('#f6f8fa', (string) $view->style()->background);
        self::assertEquals([self::dropped('syntax highlighting (install phiki/phiki)')], $view->notes());
    }

    public function test_strict_pdf_export_throws_for_a_dropped_highlight(): void
    {
        $doc = Document::make(Page::a4()->body(self::withoutHighlighter()));
        $this->expectException(UnsupportedFeature::class);
        (new PdfExporter())->export($doc, new PdfOptions(strict: true));
    }

    public function test_dark_theme_colours_the_box_and_label(): void
    {
        $view = CodeBlock::make('<?php')->language('php')->theme('github-dark')->render();
        self::assertSame('#24292e', (string) $view->style()->background);
        self::assertSame('#64748b', (string) self::textAt($view, 0)->style()->color);
        self::assertSame('#334155', (string) $view->style()->borderColor);
    }

    public function test_strip_variant_puts_the_label_in_a_bar(): void
    {
        $view = CodeBlock::make('<?php')->language('php')->variant('strip')->render();
        $bar = $view->children()[0];
        self::assertInstanceOf(View::class, $bar);
        self::assertSame('#f1f5f9', (string) $bar->style()->background);
        self::assertInstanceOf(Text::class, $bar->children()[0]);
        self::assertSame('PHP', self::words(self::textAt($bar, 0)));
        self::assertSame(\Papercut\Style\TextAlign::Left, \Papercut\Decorator\DefaultStyles::get('code-block.label.strip')?->textAlign);
        $ops = self::paginate(CodeBlock::make('x')->language('php')->variant('strip'))[0]->ops;
        $label = array_values(array_filter($ops, static fn ($o) => $o instanceof \Papercut\Paint\TextRun && $o->text === 'PHP'));
        self::assertCount(1, $label);
        self::assertLessThan(30.0, $label[0]->x, 'left-aligned in the bar');
    }

    public function test_caption_variant_puts_the_label_above_the_box(): void
    {
        $view = CodeBlock::make('<?php')->language('php')->variant('caption')->render();
        [$label, $box] = $view->children();
        self::assertInstanceOf(Text::class, $label);
        self::assertInstanceOf(View::class, $box);
        self::assertSame('#94a3b8', (string) $label->style()->color);

        $lines = array_values(array_filter(self::paginate(CodeBlock::make("a\nb")->language('php')->variant('caption'))[0]->ops, static fn ($o) => $o instanceof \Papercut\Paint\Line));
        self::assertCount(1, $lines, 'the 2pt left rule');
        self::assertSame('#cbd5e1', (string) $lines[0]->color);
        self::assertSame(2.0, $lines[0]->width);
    }

    public function test_strip_and_caption_together_throw(): void
    {
        $this->expectException(InvalidVariant::class);
        CodeBlock::make('x')->variant('strip')->variant('caption');
    }

    public function test_highlighting_default_variant_applies_unless_the_block_sets_one(): void
    {
        $h = new CodeHighlighting(variant: 'caption');
        $captioned = CodeBlock::make('x')->language('php')->highlighting($h)->render();
        self::assertInstanceOf(Text::class, $captioned->children()[0]);
        $lines = array_values(array_filter(self::paginate(CodeBlock::make('x')->language('php')->highlighting($h))[0]->ops, static fn ($o) => $o instanceof \Papercut\Paint\Line));
        self::assertSame('#cbd5e1', (string) $lines[0]->color);

        $strip = CodeBlock::make('x')->language('php')->highlighting($h)->variant('strip')->render();
        self::assertInstanceOf(View::class, $strip->children()[0], 'the block variant wins; caption is not added');
    }

    public function test_own_style_overrides_theme_colours(): void
    {
        $view = CodeBlock::make('x')->language('php')->background(Color::hex('#ffffff'))->render();
        self::assertSame('#ffffff', (string) $view->style()->background);

        $caption = CodeBlock::make('x')->language('php')->variant('caption')->background(Color::hex('#ffffff'))->render();
        self::assertSame('#ffffff', (string) self::boxAt($caption, 1)->style()->background);
        self::assertNull($caption->style()->background);
    }

    public function test_empty_code_renders_a_box_with_its_label(): void
    {
        $block = CodeBlock::make('')->language('php');
        self::assertSame('PHP', self::words(self::textAt($block->render(), 0)));
        $ops = self::paginate($block)[0]->ops;
        $runs = array_values(array_filter($ops, static fn ($o) => $o instanceof \Papercut\Paint\TextRun));
        self::assertSame(['PHP'], array_map(static fn ($r) => $r->text, $runs));
        $rects = array_values(array_filter($ops, static fn ($o) => $o instanceof \Papercut\Paint\Rect));
        self::assertNotSame([], $rects);
        self::assertGreaterThan(10.0, $rects[0]->height, 'label plus padding');
        self::assertLessThan(40.0, $rects[0]->height);
        $result = (new LayoutHarness())->layout($block, 200);
        self::assertNotNull($result->fragment);
    }

    public function test_default_highlighter_is_one_shared_instance(): void
    {
        self::assertSame(CodeHighlighting::default(), CodeHighlighting::default());
        self::assertSame(CodeHighlighting::default()->highlighter(), (new CodeHighlighting())->highlighter());
        self::assertNull((new CodeHighlighting(false))->highlighter());
    }

    public function test_lays_out_inside_its_box_and_splits_between_lines(): void
    {
        $code = implode("\n", array_map(fn (int $i) => "line$i", range(1, 60)));
        $pages = self::paginate(CodeBlock::make($code)->language('php'));

        self::assertGreaterThanOrEqual(2, count($pages));
        $texts = array_map(static fn ($p) => array_values(array_map(static fn ($o) => $o->text, array_filter($p->ops, static fn ($o) => $o instanceof \Papercut\Paint\TextRun))), $pages);
        self::assertContains('PHP', $texts[0]);
        self::assertNotContains('PHP', $texts[1]);
        foreach ($pages as $p) {
            foreach ($p->ops as $o) {
                if ($o instanceof \Papercut\Paint\TextRun) {
                    self::assertGreaterThanOrEqual(10.0, $o->x);
                    self::assertLessThanOrEqual(290.0, $o->x + strlen($o->text) * 4.5);
                }
            }
        }
    }

    public function test_no_highlight_spelling_behaves_like_nohighlight(): void
    {
        $view = CodeBlock::make('a b')->language('no-highlight')->render();
        self::assertCount(1, $view->children());
        self::assertSame([], self::spanColours(self::textAt($view, 0)));
        self::assertSame([], $view->notes());
    }

    public function test_every_plain_word_is_labelled_text(): void
    {
        foreach (['text', 'plaintext', 'txt', 'plain'] as $word) {
            $view = CodeBlock::make('a')->language($word)->render();
            self::assertSame('TEXT', self::words(self::textAt($view, 0)), $word);
            self::assertSame([], $view->notes(), $word);
        }
    }

    public function test_highlighting_variant_is_validated(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CodeHighlighting(variant: 'fancy');
    }
}
