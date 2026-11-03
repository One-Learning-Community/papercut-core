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
final class Visit
{
    private ?string $path;
    /** Where the path continues from when it is built on first use (see under()). */
    private string|self $base = '';
    private ?Node $node = null;
    private ?ComputedStyle $style = null;

    /** @internal Built by TreeWalker only. */
    public function __construct(
        string $path,
        private readonly int $index,
        private readonly ?Node $parent,
        private readonly bool $inHeader,
        private readonly Region $region,
        /** @var list<array{node: Node, style: ComputedStyle}> */
        private readonly array $ancestors = [],
    ) {
        $this->path = $path;
    }

    /**
     * A visit whose path is built only when asked for: most visitors never read it,
     * and building it for every node is a measurable share of a walk.
     *
     * @internal Built by TreeWalker only.
     * @param string|self $base the parent's visit, or the region's path for a top-level node
     * @param list<array{node: Node, style: ComputedStyle}> $ancestors
     */
    public static function under(string|self $base, Node $node, ComputedStyle $style, int $index, ?Node $parent, bool $inHeader, Region $region, array $ancestors): self
    {
        $visit = new self('', $index, $parent, $inHeader, $region, $ancestors);
        $visit->path = null;
        $visit->base = $base;
        $visit->node = $node;
        $visit->style = $style;
        return $visit;
    }

    public function path(): string
    {
        if ($this->path === null) {
            assert($this->node !== null);
            $base = $this->base instanceof self ? $this->base->path() : $this->base;
            $this->path = $base . ' > ' . NodePath::segment($this->node, $this->style, $this->index);
        }
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
