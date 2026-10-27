<?php

declare(strict_types=1);

namespace Papercut\Paint;

/**
 * An entry in the PDF's bookmarks panel, pointing at the top-left of the block that set it.
 * It draws nothing.
 */
final readonly class Bookmark implements Op
{
    public function __construct(
        public int $level,
        public string $title,
        public float $x,
        public float $y,
    ) {
        if ($level < 1) {
            throw new \InvalidArgumentException('A bookmark level starts at 1.');
        }
        if (!is_finite($x) || !is_finite($y)) {
            throw new \InvalidArgumentException('Bookmark received a non-finite number.');
        }
    }
}
