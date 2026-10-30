<?php

declare(strict_types=1);

namespace Papercut\Tests\Code;

use Papercut\Code\CodeToken;
use Papercut\Code\HighlightedCode;
use Papercut\Code\PhikiHighlighter;
use Papercut\Code\UnknownLanguage;
use Papercut\Code\UnknownTheme;
use PHPUnit\Framework\TestCase;

final class PhikiHighlighterTest extends TestCase
{
    private const string FIX = __DIR__ . '/../fixtures/code';

    private static function find(HighlightedCode $h, string $needle): CodeToken
    {
        foreach ($h->lines as $line) {
            foreach ($line as $token) {
                if (str_contains($token->text, $needle)) {
                    return $token;
                }
            }
        }
        self::fail(sprintf('No token contains %s.', $needle));
    }

    private function demo(): PhikiHighlighter
    {
        return (new PhikiHighlighter())->grammar('demo', self::FIX . '/demo.tmLanguage.json')
            ->theme('demo-dark', self::FIX . '/demo-dark.json');
    }

    public function test_available(): void
    {
        self::assertTrue(PhikiHighlighter::available());
    }

    public function test_tokens_carry_theme_colours_and_font_styles(): void
    {
        $h = (new PhikiHighlighter())->highlight("<?php\n// hi\n\$x = 'a';", 'php', 'papercut-light');
        self::assertSame('#f6f8fa', (string) $h->background);
        self::assertSame('#24292f', (string) $h->foreground);
        // Phiki splits a comment into its delimiter and body; both carry the comment style.
        foreach (['//', ' hi'] as $part) {
            $comment = self::find($h, $part);
            self::assertSame('#8b949e', (string) $comment->color);
            self::assertTrue($comment->italic);
        }
        self::assertSame('#0a7a4b', (string) self::find($h, 'a')->color);
    }

    public function test_storage_keywords_take_the_keyword_colour(): void
    {
        $h = (new PhikiHighlighter())->highlight("<?php\nclass A {}\nconst X = 1;\ninterface I {}", 'php', 'papercut-light');
        foreach (['class', 'const', 'interface'] as $word) {
            self::assertSame('#8250df', (string) self::find($h, $word)->color, $word);
        }
    }

    public function test_markup_tag_and_attribute_names_are_coloured_but_tag_punctuation_is_not(): void
    {
        foreach (['html' => '<a href="x">y</a>', 'xml' => '<a href="x">y</a>', 'vue' => "<template>\n  <a href=\"x\">y</a>\n</template>"] as $lang => $code) {
            $h = (new PhikiHighlighter())->highlight($code, $lang, 'papercut-light');
            self::assertSame('#116329', (string) self::find($h, 'a')->color, $lang . ' tag name');
            self::assertSame('#0550ae', (string) self::find($h, 'href')->color, $lang . ' attribute name');
            self::assertSame('#24292f', (string) ($h->lines[0][0]->color ?? $h->foreground), $lang . ' tag punctuation stays the text colour');
        }
    }

    public function test_text_round_trips(): void
    {
        $h = new PhikiHighlighter();
        foreach (["a = 1\nb = 2", "a = 1\nb = 2\n", "a = 1\r\nb = 2\r\n", ''] as $code) {
            $lines = $h->highlight($code, 'python', 'papercut-light')->lines;
            $text = implode("\n", array_map(static fn (array $l): string => implode('', array_map(static fn (CodeToken $t): string => $t->text, $l)), $lines));
            // The adapter keeps every line the author wrote, including trailing blank ones.
            self::assertSame(str_replace(["\r\n", "\r"], "\n", $code), $text);
        }
    }

    public function test_language_names_are_case_insensitive_and_aliases_resolve(): void
    {
        $h = new PhikiHighlighter();
        self::assertSame('PHP', $h->displayName('PHP'));
        self::assertSame('JavaScript', $h->displayName('js'));
        self::assertSame('Shell', $h->displayName('sh'));
        self::assertNull($h->displayName('nosuchlang'));
        self::assertEquals($h->highlight('$a = 1;', 'php', 'papercut-light'), $h->highlight('$a = 1;', 'PHP', 'papercut-light'));
    }

    public function test_unknown_language_throws(): void
    {
        $this->expectException(UnknownLanguage::class);
        (new PhikiHighlighter())->highlight('x', 'nosuchlang', 'papercut-light');
    }

    public function test_unknown_theme_throws(): void
    {
        $this->expectException(UnknownTheme::class);
        (new PhikiHighlighter())->highlight('x', 'php', 'no-such-theme');
    }

    public function test_bundled_themes_have_editor_colours(): void
    {
        $h = (new PhikiHighlighter())->highlight('x', 'php', 'github-dark');
        self::assertSame('#24292e', (string) $h->background);
    }

    public function test_custom_grammar_theme_alias_and_display_name(): void
    {
        $h = $this->demo()->alias('dm', 'demo');
        $out = $h->highlight('demo x', 'dm', 'demo-dark');
        self::assertSame('#101010', (string) $out->background);
        self::assertSame('#eeeeee', (string) $out->foreground);
        self::assertSame('#00ff00', (string) self::find($out, 'demo')->color);
        self::assertTrue(self::find($out, 'demo')->bold);
        self::assertSame('Demo', $h->displayName('demo'));
        self::assertSame('Demo', $h->displayName('dm'));
    }

    public function test_injection_applies_inside_nested_embedded_code(): void
    {
        $h = $this->demo()->injection(self::FIX . '/demo-sigils.injection.json', ['source.demo']);
        $out = $h->highlight("<<ts\nconst f = () => { return @@data }\nts>>", 'demo', 'demo-dark');
        self::assertSame('#ff00ff', (string) self::find($out, '@@data')->color);
    }

    public function test_injection_repository_does_not_overwrite_the_hosts(): void
    {
        $h = $this->demo()->injection(self::FIX . '/demo-sigils.injection.json', ['source.demo']);
        $out = $h->highlight('!!x @@y', 'demo', 'demo-dark');
        self::assertSame('#00ff00', (string) self::find($out, '!!x')->color);
        self::assertSame('#ff00ff', (string) self::find($out, '@@y')->color);
    }

    public function test_registering_an_unreadable_file_names_its_path(): void
    {
        foreach (['grammar', 'theme'] as $method) {
            try {
                (new PhikiHighlighter())->$method('x', '/nope/missing.json');
                self::fail('Expected an exception.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('/nope/missing.json', $e->getMessage());
            }
        }
        try {
            (new PhikiHighlighter())->injection('/nope/inj.json', ['source.demo']);
            self::fail('Expected an exception.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('/nope/inj.json', $e->getMessage());
        }
    }

    public function test_invalid_json_names_its_path(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bad');
        self::assertIsString($path);
        file_put_contents($path, '{nope');
        try {
            (new PhikiHighlighter())->grammar('x', $path);
            self::fail('Expected an exception.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($path, $e->getMessage());
        } finally {
            unlink($path);
        }
    }

    public function test_registered_grammar_can_be_embedded_by_scope(): void
    {
        $h = $this->demo()->grammar('inner', self::FIX . '/inner.json')->grammar('outer', self::FIX . '/outer.json');
        $out = $h->highlight("<<\nzzz\n>>", 'outer', 'demo-dark');
        self::assertSame('#00ff00', (string) self::find($out, 'zzz')->color);
    }

    public function test_injection_applies_when_the_host_is_the_root_language_only(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'inj');
        self::assertIsString($path);
        $json = json_decode((string) file_get_contents(self::FIX . '/demo-sigils.injection.json'), true);
        self::assertIsArray($json);
        $json['injectionSelector'] = 'L:source.ts';
        file_put_contents($path, json_encode($json));
        try {
            $h = $this->demo()->injection($path, ['source.ts']);
        } finally {
            unlink($path);
        }
        $inEmbedded = $h->highlight("<<ts\nconst f = @@data\nts>>", 'demo', 'demo-dark');
        self::assertNotSame('#ff00ff', (string) self::find($inEmbedded, '@@')->color);
        $root = $h->highlight('const f = @@data', 'ts', 'demo-dark');
        self::assertSame('#ff00ff', (string) self::find($root, '@@')->color);
    }

    public function test_injection_into_an_unknown_scope_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('source.nope');
        $this->expectExceptionMessage('demo-sigils.injection.json');
        $this->demo()->injection(self::FIX . '/demo-sigils.injection.json', ['source.nope']);
    }

    public function test_injection_into_nothing_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->demo()->injection(self::FIX . '/demo-sigils.injection.json', []);
    }

    public function test_highlighting_does_not_read_the_injection_file_again(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'inj');
        self::assertIsString($path);
        copy(self::FIX . '/demo-sigils.injection.json', $path);
        $h = $this->demo()->injection($path, ['source.demo']);
        unlink($path);
        $out = $h->highlight('@@y', 'demo', 'demo-dark');
        self::assertSame('#ff00ff', (string) self::find($out, '@@y')->color);
    }

    public function test_theme_names_are_case_insensitive(): void
    {
        $h = $this->demo();
        self::assertSame('#101010', (string) $h->highlight('x', 'demo', 'Demo-Dark')->background);
    }

    public function test_unknown_language_message_uses_the_name_as_given(): void
    {
        $this->expectException(UnknownLanguage::class);
        $this->expectExceptionMessage('NoSuchLang');
        (new PhikiHighlighter())->highlight('x', 'NoSuchLang', 'papercut-light');
    }
}
