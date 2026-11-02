<?php

declare(strict_types=1);

namespace Papercut\Text;

use Papercut\Style\ComputedStyle;

/** @internal Part of Papercut's engine, not its public API. It may change in any release. */
final readonly class InlineItem
{
    /**
     * @param 'word'|'space'|'break'|'tab' $kind a 'tab' carries the stop pitch (four space advances) as its width until
     *                                            LineBreaker replaces it with the distance to the next stop
     */
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

    public function withWidth(float $width): self
    {
        return new self($this->kind, $this->text, $this->font, $this->size, $width, $this->style, $this->href, $this->breakAfter);
    }

    public function withText(string $text, float $width, bool $breakAfter): self
    {
        return new self($this->kind, $text, $this->font, $this->size, $width, $this->style, $this->href, $breakAfter);
    }
}
