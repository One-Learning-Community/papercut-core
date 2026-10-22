<?php

declare(strict_types=1);

namespace Papercut;

use Papercut\Style\Style;

/**
 * A node with the HasStyle modifiers: its own style, an optional theme style
 * name resolved at layout time, and immutable style application.
 */
interface Styled extends Node
{
    public function styleName(): ?string;

    public function apply(Style|string $style): static;
}
