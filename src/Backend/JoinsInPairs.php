<?php

declare(strict_types=1);

namespace Papercut\Backend;

/**
 * A font whose width for joined text is the parts' widths plus pair kerning where they meet: no ligature or other
 * substitution can form across the join. Layout then measures a merged run from its joins instead of shaping it whole.
 *
 * @internal Part of Papercut's engine, not its public API. It may change in any release.
 */
interface JoinsInPairs
{
    public function joinsInPairs(): bool;
}
