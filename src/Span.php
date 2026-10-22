<?php

declare(strict_types=1);

namespace Papercut;

use Papercut\Style\Style;

final readonly class Span
{
    public function __construct(
        public string $text,
        public Style $style = new Style(),
        public ?string $href = null,
        public bool $lineBreak = false,
    ) {
    }

    public static function text(string $text, ?Style $style = null): self
    {
        return new self($text, $style ?? new Style());
    }

    public static function break(): self
    {
        return new self('', new Style(), null, true);
    }
}
