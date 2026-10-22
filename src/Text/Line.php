<?php

declare(strict_types=1);

namespace Papercut\Text;

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
