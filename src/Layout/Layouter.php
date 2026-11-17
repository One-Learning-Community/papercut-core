<?php

declare(strict_types=1);

namespace Quoin\Layout;

use Quoin\Node;
use Quoin\Style\ComputedStyle;

/** @internal Engine detail, not public API. It may change in any release. */
interface Layouter
{
    public function layout(Node $node, ComputedStyle $style, Constraints $c, ?BreakToken $token, LayoutEngine $engine): LayoutResult;

    /** @return array{min: float, max: float} */
    public function intrinsic(Node $node, ComputedStyle $style, LayoutEngine $engine): array;
}
