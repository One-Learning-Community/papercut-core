<?php

declare(strict_types=1);

namespace Quoin\Layout;

/**
 * One flex item's main-axis placement: $x from the row's content box, $width its border-box width.
 *
 * @internal Engine detail, not public API. It may change in any release.
 */
final readonly class FlexSlot
{
    public function __construct(public int $index, public float $x, public float $width)
    {
    }
}
