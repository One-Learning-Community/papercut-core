<?php

declare(strict_types=1);

namespace Papercut\Toc;

/** One heading a table of contents can list. $key is the node's layout path. */
final readonly class TocEntry
{
    public function __construct(
        public string $key,
        public int $level,
        public string $text,
        public string $destination,
    ) {
    }
}
