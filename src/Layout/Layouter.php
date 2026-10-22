<?php

declare(strict_types=1);

namespace Papercut\Layout;

use Papercut\Node;
use Papercut\Style\ComputedStyle;

interface Layouter
{
    public function layout(Node $node, ComputedStyle $style, Constraints $c, ?BreakToken $token, LayoutEngine $engine): LayoutResult;

    /** @return array{min: float, max: float} */
    public function intrinsic(Node $node, ComputedStyle $style, LayoutEngine $engine): array;
}
