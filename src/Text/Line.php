<?php

declare(strict_types=1);

namespace Quoin\Text;

/** @internal Engine detail, not public API. It may change in any release. */
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
