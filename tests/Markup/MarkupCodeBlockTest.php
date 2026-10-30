<?php

declare(strict_types=1);

namespace Papercut\Tests\Markup;

use Papercut\{Text, View};
use Papercut\Export\FidelityLevel;
use Papercut\Markup\{Markup, MarkupOptions, StyleMode};
use PHPUnit\Framework\TestCase;

final class MarkupCodeBlockTest extends TestCase
{
    /** @return list<Text> every Text in the tree */
    private static function texts(View|Text $node): array
    {
        if ($node instanceof Text) {
            return [$node];
        }
        $out = [];
        foreach ($node->children() as $child) {
            if (!$child instanceof View && !$child instanceof Text) {
                continue;
            }
            $out = [...$out, ...self::texts($child)];
        }
        return $out;
    }

    private static function plain(View|Text $node): string
    {
        return implode("\n", array_map(
            static fn (Text $t): string => implode('', array_map(static fn ($s) => $s->lineBreak ? "\n" : $s->text, $t->spanList())),
            self::texts($node),
        ));
    }

    private static function hasColour(View|Text $node): bool
    {
        foreach (self::texts($node) as $t) {
            foreach ($t->spanList() as $s) {
                if ($s->style->color !== null) {
                    return true;
                }
            }
        }
        return false;
    }

    public function test_only_one_trailing_newline_is_dropped_so_blank_lines_survive(): void
    {
        foreach (['php', 'text'] as $lang) {
            $wrap = static fn (string $code): View|Text => Markup::html('<pre><code class="language-' . $lang . '">' . $code . '</code></pre>');
            self::assertEquals($wrap('a'), $wrap("a\n"), $lang . ': one final newline just ends the line');
            self::assertNotEquals($wrap("a\n"), $wrap("a\n\n"), $lang . ': the second newline is a blank line');
            self::assertEquals($wrap("a\n\n"), $wrap("a\n\n"));
            self::assertNotEquals($wrap("a\n\n"), $wrap("a\n\n\n"), $lang . ': and a third is another');
        }
    }

    public function test_language_class_on_code_makes_a_code_block(): void
    {
        $v = Markup::html('<pre><code class="language-php">$a = 1;</code></pre>');
        self::assertInstanceOf(View::class, $v);
        self::assertStringContainsString('PHP', self::plain($v));
        self::assertTrue(self::hasColour($v));
    }

    public function test_lang_class_and_class_on_pre_work_too(): void
    {
        $js = Markup::html('<pre><code class="lang-js">let a = 1;</code></pre>');
        self::assertStringStartsWith("JAVASCRIPT\n", self::plain($js));
        $lang = Markup::html('<pre class="lang-sql"><code>SELECT 1;</code></pre>');
        self::assertStringStartsWith("SQL\n", self::plain($lang));
        $sql = Markup::html('<pre class="language-sql"><code>SELECT 1;</code></pre>');
        self::assertStringContainsString('SQL', self::plain($sql));
        self::assertTrue(self::hasColour($sql));
    }

    public function test_switch_off_keeps_the_plain_pre(): void
    {
        $html = '<pre><code class="language-php">$a = 1;</code></pre>';
        $off = Markup::html($html, new MarkupOptions(code: false));
        self::assertStringNotContainsString('PHP', self::plain($off));
        self::assertFalse(self::hasColour($off));
        self::assertEquals(Markup::html('<pre><code>$a = 1;</code></pre>', new MarkupOptions(code: false)), $off);
    }

    public function test_pre_without_a_language_is_unchanged(): void
    {
        $html = '<pre><code>$a = 1;</code></pre>';
        self::assertEquals(
            Markup::html($html, new MarkupOptions(code: false)),
            Markup::html($html),
        );
    }

    public function test_child_elements_are_flattened_and_rehighlighted(): void
    {
        $v = Markup::html('<pre><code class="language-php"><span class="hljs-keyword">echo</span> 1;</code></pre>');
        self::assertStringContainsString('echo 1;', self::plain($v));
        self::assertSame([], $v->notes());
    }

    public function test_br_becomes_a_newline(): void
    {
        $v = Markup::html('<pre><code class="language-php">a<br>b</code></pre>');
        self::assertStringContainsString("a\nb", self::plain($v));
    }

    public function test_links_inside_are_dropped_with_an_approximated_note(): void
    {
        $v = Markup::html('<pre><code class="language-php">see <a href="/x">x</a></code></pre>');
        $notes = $v->notes();
        self::assertCount(1, $notes);
        self::assertSame(FidelityLevel::Approximated, $notes[0]->level);
        self::assertSame('markup inside a code block', $notes[0]->feature);
    }

    public function test_unknown_class_words_are_ignored(): void
    {
        $v = Markup::html('<pre><code class="hljs language-php foo">$a = 1;</code></pre>');
        self::assertStringContainsString('PHP', self::plain($v));
    }

    public function test_outer_preset_is_kept_for_text_before_the_block(): void
    {
        $v = Markup::html('<h2>Head<pre class="language-php">x</pre></h2>');
        $first = self::texts($v)[0];
        self::assertSame('Head', $first->spanList()[0]->text);
        self::assertSame('h2', $first->preset());
    }

    public function test_original_mode_inline_style_applies_to_the_block(): void
    {
        $v = Markup::html('<pre class="language-php" style="font-size:20pt">x</pre>', new MarkupOptions(styles: StyleMode::Original));
        $plain = Markup::html('<pre class="language-php">x</pre>', new MarkupOptions(styles: StyleMode::Original));
        self::assertNotEquals($plain, $v);
        self::assertInstanceOf(View::class, $v);
        $block = $v->children()[0];
        self::assertInstanceOf(View::class, $block);
        self::assertSame(20.0, $block->style()->fontSize?->points);
    }

    public function test_text_beside_a_lone_code_element_is_kept(): void
    {
        self::assertStringContainsString('prefixx', str_replace("\n", '', self::plain(Markup::html('<pre class="language-php">prefix<code>x</code></pre>'))));
        $mixed = self::plain(Markup::html('<pre class="language-php">a<code>b</code>c</pre>'));
        self::assertStringContainsString('abc', $mixed);
    }

    public function test_whitespace_around_a_lone_code_element_is_skipped(): void
    {
        $tight = Markup::html('<pre class="language-php"><code>x</code></pre>');
        $loose = Markup::html("<pre class=\"language-php\">\n  <code>x</code>\n</pre>");
        self::assertSame(self::plain($tight), self::plain($loose));
    }

    public function test_several_code_children_raise_no_note(): void
    {
        $v = Markup::html('<pre class="language-php"><code>a</code><code>b</code></pre>');
        self::assertSame([], $v->notes());
        self::assertStringContainsString('ab', self::plain($v));
    }

    public function test_one_final_newline_is_dropped(): void
    {
        $one = Markup::html("<pre><code class=\"language-php\">x\n</code></pre>");
        $bare = Markup::html('<pre><code class="language-php">x</code></pre>');
        self::assertSame(self::plain($bare), self::plain($one));
    }
}
