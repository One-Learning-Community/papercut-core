<?php

declare(strict_types=1);

namespace Papercut\Layout;

/**
 * Where a Partial layout stopped. $index is the next line for text, or the
 * next child/row index for containers and tables. $parallel holds per-child
 * tokens for rows and table cells split in parallel; a null entry means that
 * child has finished.
 */
final readonly class BreakToken
{
    /** @param array<int, ?BreakToken> $parallel */
    public function __construct(
        public int $index = 0,
        public ?BreakToken $child = null,
        public array $parallel = [],
        public bool $headerPending = false,
    ) {
    }
}
