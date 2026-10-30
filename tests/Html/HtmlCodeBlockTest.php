<?php

declare(strict_types=1);

namespace Papercut\Tests\Html;

use Papercut\Decorator\CodeBlock;
use Papercut\{Document, Page};
use PHPUnit\Framework\TestCase;

final class HtmlCodeBlockTest extends TestCase
{
    private function html(CodeBlock $block): string
    {
        return Document::make(Page::a4()->body($block))->toHtml()->bytes();
    }

    public function test_code_exports_as_pre_with_coloured_token_spans(): void
    {
        $html = $this->html(CodeBlock::make("<?php\n// note\n\$a = 1;")->language('php'));
        self::assertStringContainsString('<pre', $html);
        self::assertMatchesRegularExpression('~<span style="[^"]*color:#[0-9a-fA-F]{6}~', $html);
        self::assertStringContainsString(' note', $html);
    }

    public function test_box_background_is_inline(): void
    {
        $html = $this->html(CodeBlock::make('echo 1;')->language('php'));
        self::assertMatchesRegularExpression('~style="[^"]*background(-color)?:#f6f8fa~i', $html);
    }

    public function test_label_carries_the_muted_colour(): void
    {
        $html = $this->html(CodeBlock::make('echo 1;')->language('php'));
        self::assertMatchesRegularExpression('~<[a-z]+ style="[^"]*color:#94a3b8[^"]*">PHP<~i', $html);
    }

    public function test_code_is_wrapped_in_code_with_a_language_class(): void
    {
        $html = $this->html(CodeBlock::make('echo 1;')->language('php'));
        self::assertMatchesRegularExpression('~<pre[^>]*><code class="language-php">.*</code></pre>~s', $html);
    }

    public function test_code_without_a_language_has_no_class(): void
    {
        $html = $this->html(CodeBlock::make('echo 1;'));
        self::assertMatchesRegularExpression('~<pre[^>]*><code>.*</code></pre>~s', $html);
        self::assertStringNotContainsString('language-', $html);
    }
}
