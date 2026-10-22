<?php

declare(strict_types=1);

namespace Papercut\Paint;

use Papercut\Value\Color;

final readonly class Rect implements Op
{
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public ?Color $fill = null,
        public ?Color $stroke = null,
        public float $strokeWidth = 0.0,
        public float $radius = 0.0,
    ) {
        if (!is_finite($x) || !is_finite($y) || !is_finite($width) || !is_finite($height) || !is_finite($strokeWidth) || !is_finite($radius)) {
            throw new \InvalidArgumentException('Rect received a non-finite number.');
        }
        if (!($width >= 0) || !($height >= 0) || !($strokeWidth >= 0) || !($radius >= 0)) {
            throw new \InvalidArgumentException('Rect dimensions, stroke width and radius must be non-negative.');
        }
    }
}
