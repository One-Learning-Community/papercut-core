<?php

declare(strict_types=1);

namespace Papercut\Paint;

use Papercut\Style\TextAlign;
use Papercut\Text\CachedFont;
use Papercut\Value\Color;

/**
 * A page-number placeholder carried in fragments. The Painter replaces
 * {page} and {total}, then aligns the text within $width.
 */
final readonly class PageNumberSlot
{
    public function __construct(
        public float $x,
        public float $baseline,
        public float $width,
        public string $format,
        public CachedFont $font,
        public float $size,
        public Color $color,
        public TextAlign $align,
        public float $letterSpacing,
    ) {
    }
}
