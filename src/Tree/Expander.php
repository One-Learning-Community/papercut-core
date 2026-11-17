<?php

declare(strict_types=1);

namespace Quoin\Tree;

use Quoin\{Component, Node, ThemedComponent};
use Quoin\Style\Theme;

/**
 * Renders Components down to a Node.
 *
 * @internal Engine detail, not public API. It may change in any release.
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
