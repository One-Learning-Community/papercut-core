<?php

declare(strict_types=1);

namespace Papercut\Text;

/** @internal Part of Papercut's engine, not its public API. It may change in any release. */
final readonly class Line
{
    /** @param list<InlineItem> $items */
    public function __construct(
        public array $items,
        public float $width,
        public bool $endsWithBreak,
        public bool $isLast,
    ) {
    }
}
