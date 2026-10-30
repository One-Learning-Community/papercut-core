<?php

declare(strict_types=1);

namespace Papercut\Markup;

use Papercut\Code\CodeHighlighting;

/**
 * Options for Markup conversion; `$images` is `\Closure(string $src): ?string` returning image bytes.
 * `$code` highlights `<pre><code class="language-x">`; `false` keeps those as plain preformatted text.
 */
final readonly class MarkupOptions
{
    public function __construct(
        public StyleMode $styles = StyleMode::Theme,
        public ?\Closure $images = null,
        public BlankParagraphs $blankParagraphs = BlankParagraphs::Drop,
        public CodeHighlighting|false $code = new CodeHighlighting(),
    ) {
    }
}
