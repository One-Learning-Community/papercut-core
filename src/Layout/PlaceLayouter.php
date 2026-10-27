<?php

declare(strict_types=1);

namespace Papercut\Layout;

use Papercut\Node;
use Papercut\Style\ComputedStyle;

/** A place is laid out by the paginator, in the page overlay; anywhere else it is a mistake. */
final class PlaceLayouter implements Layouter
{
    public function layout(Node $node, ComputedStyle $style, Constraints $c, ?BreakToken $token, LayoutEngine $engine): LayoutResult
    {
        throw self::misplaced($engine);
    }

    public function intrinsic(Node $node, ComputedStyle $style, LayoutEngine $engine): array
    {
        throw self::misplaced($engine);
    }

    private static function misplaced(LayoutEngine $engine): \LogicException
    {
        return new \LogicException(sprintf('A place only works in a page overlay; add it with Page::overlay(), not inside other content (at %s).', $engine->context()->path()));
    }
}
