<?php

declare(strict_types=1);

namespace Papercut\Layout;

/** One flex item's main-axis placement: $x from the row's content box, $width its border-box width. */
final readonly class FlexSlot
{
    public function __construct(public int $index, public float $x, public float $width)
    {
    }
}
