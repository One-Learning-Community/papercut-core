<?php

declare(strict_types=1);

namespace Papercut\Paint;

use Papercut\Backend\ImageHandle;

final readonly class DrawImage implements Op
{
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public ImageHandle $image,
    ) {
        if (!is_finite($x) || !is_finite($y) || !is_finite($width) || !is_finite($height)) {
            throw new \InvalidArgumentException('DrawImage received a non-finite number.');
        }
    }
}
