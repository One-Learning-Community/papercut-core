<?php

declare(strict_types=1);

namespace Papercut\Code;

use Papercut\Value\Color;

final readonly class HighlightedCode
{
    /**
     * @param list<list<CodeToken>> $lines One entry per source line; a line holds no newline characters.
     */
    public function __construct(
        public array $lines,
        public Color $background,
        public Color $foreground,
    ) {
    }
}
