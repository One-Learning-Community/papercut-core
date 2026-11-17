<?php

declare(strict_types=1);

namespace Quoin\Layout;

/**
 * One physical page: its size and the fragments placed on it in paint order
 * (background, header, body, footer), at absolute page coordinates.
 *
 * @internal Engine detail, not public API. It may change in any release.
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
