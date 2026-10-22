<?php

declare(strict_types=1);

namespace Papercut\Layout;

/**
 * One physical page: its size and the fragments placed on it in paint order
 * (background, header, body, footer), at absolute page coordinates.
 */
final readonly class PlacedPage
{
    /** @param list<array{x: float, y: float, fragment: Fragment}> $layers */
    public function __construct(
        public float $width,
        public float $height,
        /** @var list<array{x: float, y: float, fragment: Fragment}> */
        public array $layers,
    ) {
    }
}
