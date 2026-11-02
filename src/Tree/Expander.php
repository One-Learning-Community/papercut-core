<?php

declare(strict_types=1);

namespace Papercut\Tree;

use Papercut\{Component, Node, ThemedComponent};
use Papercut\Style\Theme;

/**
 * Renders Components down to a Node.
 *
 * @internal Part of Papercut's engine, not its public API. It may change in any release.
 */
final class Expander
{
    public const int MAX_DEPTH = 100;

    public static function expand(Node|Component $node, ?Theme $theme = null): Node
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
            $node = $theme !== null && $node instanceof ThemedComponent ? $node->renderFor($theme) : $node->render();
        }
        return $node;
    }
}
