<?php

declare(strict_types=1);

namespace Papercut\Paint;

final readonly class Opacity implements Op
{
    /**
     * @param list<Op> $ops
     */
    public function __construct(
        public float $alpha,
        /** @var list<Op> */
        public array $ops,
    ) {
        if (!is_finite($alpha)) {
            throw new \InvalidArgumentException('Opacity received a non-finite number.');
        }
        if (!($alpha >= 0 && $alpha <= 1)) {
            throw new \InvalidArgumentException('Opacity alpha must be between 0 and 1.');
        }
    }
}
