<?php

declare(strict_types=1);

namespace Papercut\Text;

use Papercut\Math\Layout\Box;
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
        /** An inline box (a badge label): $width includes the style's padding and border either side of the text. */
        public bool $box = false,
        /** An inline equation: $width is its width, it has no text, and it is never split. */
        public ?Box $math = null,
    ) {
    }

    /** The padding and border either side of an inline box's text, together. */
    public function boxEdges(): float
    {
        return $this->style->padding->horizontal() + $this->style->borderWidth->horizontal();
    }

    public function withWidth(float $width): self
    {
        return new self($this->kind, $this->text, $this->font, $this->size, $width, $this->style, $this->href, $this->breakAfter, $this->box, $this->math);
    }

    public function withText(string $text, float $width, bool $breakAfter): self
    {
        return new self($this->kind, $text, $this->font, $this->size, $width, $this->style, $this->href, $breakAfter, $this->box, $this->math);
    }
}
