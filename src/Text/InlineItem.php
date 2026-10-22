<?php

declare(strict_types=1);

namespace Papercut\Text;

use Papercut\Style\ComputedStyle;

final readonly class InlineItem
{
    /** @param 'word'|'space'|'break' $kind */
    public function __construct(
        public string $kind,
        public string $text,
        public CachedFont $font,
        public float $size,
        public float $width,
        public ComputedStyle $style,
        public ?string $href = null,
        public bool $breakAfter = false,
    ) {
    }

    public function withText(string $text, float $width, bool $breakAfter): self
    {
        return new self($this->kind, $text, $this->font, $this->size, $width, $this->style, $this->href, $breakAfter);
    }
}
