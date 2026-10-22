<?php

declare(strict_types=1);

namespace Papercut\Paint;

final readonly class PageDisplayList
{
    /**
     * @param list<Op> $ops
     */
    public function __construct(
        public float $width,
        public float $height,
        /** @var list<Op> */
        public array $ops,
    ) {
        if (!is_finite($width) || !is_finite($height)) {
            throw new \InvalidArgumentException('PageDisplayList received a non-finite number.');
        }
        if (!($width > 0) || !($height > 0)) {
            throw new \InvalidArgumentException('Page width and height must be positive.');
        }
    }
}
