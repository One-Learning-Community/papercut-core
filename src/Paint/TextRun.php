<?php

declare(strict_types=1);

namespace Papercut\Paint;

use Papercut\Backend\FontHandle;
use Papercut\Value\Color;

final readonly class TextRun implements Op
{
    public function __construct(
        public float $x,
        public float $baseline,
        public string $text,
        public FontHandle $font,
        public float $size,
        public Color $color,
        public float $letterSpacing = 0.0,
    ) {
        if (!is_finite($x) || !is_finite($baseline) || !is_finite($size) || !is_finite($letterSpacing)) {
            throw new \InvalidArgumentException('TextRun received a non-finite number.');
        }
        if (!($size > 0)) {
            throw new \InvalidArgumentException('Text size must be positive.');
        }
    }
}
