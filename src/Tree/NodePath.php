<?php

declare(strict_types=1);

namespace Papercut\Tree;

use Papercut\{Node, View};
use Papercut\Style\{ComputedStyle, Direction};

final class NodePath
{
    /** Views are named by their computed direction; debugName() can be stale after attributes(). */
    public static function segment(Node $node, ?ComputedStyle $style, ?int $index): string
    {
        $name = $node instanceof View && $style !== null
            ? ($style->direction === Direction::Row ? 'row' : 'column')
            : $node->debugName();
        return $index === null ? $name : sprintf('%s[%d]', $name, $index);
    }
}
