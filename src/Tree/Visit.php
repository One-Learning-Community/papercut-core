<?php

declare(strict_types=1);

namespace Papercut\Tree;

use Papercut\Node;

/**
 * Where a node sits in the walk. Built by TreeWalker; visitors only read it.
 */
final readonly class Visit
{
    /** @internal Built by TreeWalker only. */
    public function __construct(
        private string $path,
        private int $index,
        private ?Node $parent,
        private bool $inHeader,
        private Region $region,
    ) {
    }

    public function path(): string
    {
        return $this->path;
    }

    /** Sibling index in the parent's original child list (table rows: header rows, then body rows). */
    public function index(): int
    {
        return $this->index;
    }

    /** Null for a region's top-level nodes. */
    public function parent(): ?Node
    {
        return $this->parent;
    }

    /** True within the nearest table's header rows: a header TableRow and everything inside it. */
    public function inHeader(): bool
    {
        return $this->inHeader;
    }

    public function region(): Region
    {
        return $this->region;
    }
}
