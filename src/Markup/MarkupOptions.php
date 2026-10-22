<?php

declare(strict_types=1);

namespace Papercut\Markup;

/** Options for Markup conversion; `$images` is `\Closure(string $src): ?string` returning image bytes. */
final readonly class MarkupOptions
{
    public function __construct(
        public StyleMode $styles = StyleMode::Theme,
        public ?\Closure $images = null,
        public BlankParagraphs $blankParagraphs = BlankParagraphs::Drop,
    ) {
    }
}
