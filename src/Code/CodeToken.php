<?php

declare(strict_types=1);

namespace Papercut\Code;

use Papercut\Value\Color;

final readonly class CodeToken
{
    public function __construct(
        public string $text,
        public ?Color $color = null,
        public bool $bold = false,
        public bool $italic = false,
        public bool $underline = false,
    ) {
    }
}
