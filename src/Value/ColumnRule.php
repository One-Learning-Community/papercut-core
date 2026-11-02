<?php

declare(strict_types=1);

namespace Papercut\Value;

/** The line drawn between the columns of a ColumnFlow. */
final readonly class ColumnRule
{
    public function __construct(
        public float $width,
        public Color $color,
    ) {
    }
}
