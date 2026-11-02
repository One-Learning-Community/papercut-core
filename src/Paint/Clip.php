<?php

declare(strict_types=1);

namespace Papercut\Paint;

/** @internal Part of the display list between layout and the PDF writer. It may change in any release. */
final readonly class Clip implements Op
{
    /**
     * @param list<Op> $ops
     */
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        /** @var list<Op> */
        public array $ops,
    ) {
        if (!is_finite($x) || !is_finite($y) || !is_finite($width) || !is_finite($height)) {
            throw new \InvalidArgumentException('Clip received a non-finite number.');
        }
    }
}
