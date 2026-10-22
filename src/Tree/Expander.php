<?php

declare(strict_types=1);

namespace Papercut\Tree;

use Papercut\{Component, Node};

/** Renders Components down to a Node. */
final class Expander
{
    public const int MAX_DEPTH = 100;

    public static function expand(Node|Component $node): Node
    {
        $depth = 0;
        while (!$node instanceof Node) {
            if (++$depth > self::MAX_DEPTH) {
                throw new \LogicException(sprintf(
                    'Component %s did not render to a node within %d levels; check for a component that renders itself.',
                    $node::class,
                    self::MAX_DEPTH,
                ));
            }
            $node = $node->render();
        }
        return $node;
    }
}
