<?php

declare(strict_types=1);

namespace Papercut\Style;

use Papercut\Node;

/**
 * Computed styles remembered per node and parent style, both by identity.
 * A node nearly always has one parent, so the first parent and its style sit
 * in two flat maps; a node met under another parent gets a map of its own.
 * Flat maps cost about a fifth of a map per node, and a long table has tens
 * of thousands of nodes.
 *
 * @internal Part of Papercut's engine, not its public API. It may change in any release.
 */
final class StyleMemo
{
    /** @var \SplObjectStorage<Node, ComputedStyle> */
    private \SplObjectStorage $parents;
    /** @var \SplObjectStorage<Node, ComputedStyle> */
    private \SplObjectStorage $styles;
    /** @var \SplObjectStorage<Node, \SplObjectStorage<ComputedStyle, ComputedStyle>> styles under any other parent */
    private \SplObjectStorage $others;

    public function __construct()
    {
        $this->parents = new \SplObjectStorage();
        $this->styles = new \SplObjectStorage();
        $this->others = new \SplObjectStorage();
    }

    public function get(Node $node, ComputedStyle $parent): ?ComputedStyle
    {
        $first = $this->parents[$node] ?? null;
        if ($first === $parent) {
            return $this->styles[$node];
        }
        if ($first === null) {
            return null;
        }
        return ($this->others[$node] ?? null)[$parent] ?? null;
    }

    public function put(Node $node, ComputedStyle $parent, ComputedStyle $style): ComputedStyle
    {
        if (!isset($this->parents[$node])) {
            $this->parents[$node] = $parent;
            $this->styles[$node] = $style;
            return $style;
        }
        $map = $this->others[$node] ?? null;
        if ($map === null) {
            /** @var \SplObjectStorage<ComputedStyle, ComputedStyle> $map */
            $map = new \SplObjectStorage();
            $this->others[$node] = $map;
        }
        $map[$parent] = $style;
        return $style;
    }
}
