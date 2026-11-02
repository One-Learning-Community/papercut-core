<?php

declare(strict_types=1);

namespace Papercut\Tree;

use Papercut\Node;
use Papercut\Style\ComputedStyle;

/**
 * Where a node sits in the walk. Built by TreeWalker; visitors only read it.
 *
 * @internal Part of Papercut's engine, not its public API. It may change in any release.
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
        /** @var list<array{node: Node, style: ComputedStyle}> */
        private array $ancestors = [],
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

    /**
     * Every enclosing node with its computed style, from the region's top-level node down to the parent
     * (empty for a top-level node). Table rows, cells and list items are included.
     *
     * @return list<array{node: Node, style: ComputedStyle}>
     */
    public function ancestors(): array
    {
        return $this->ancestors;
    }

    public function region(): Region
    {
        return $this->region;
    }
}
