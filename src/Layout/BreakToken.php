<?php

declare(strict_types=1);

namespace Papercut\Layout;

/**
 * Where a Partial layout stopped. $index is the next line for text, or the
 * next child/row index for containers and tables. $parallel holds per-child
 * tokens for rows and table cells split in parallel; a null entry means that
 * child has finished. $forced is set when the layout stopped on purpose (a
 * page or column break) rather than because space ran out.
 *
 * @internal Part of Papercut's engine, not its public API. It may change in any release.
 */
final readonly class BreakToken
{
    /** @param array<int, ?BreakToken> $parallel */
    public function __construct(
        public int $index = 0,
        public ?BreakToken $child = null,
        public array $parallel = [],
        public bool $headerPending = false,
        public ?ForcedBreak $forced = null,
    ) {
    }
}
