<?php

declare(strict_types=1);

namespace Papercut\Decorator;

use Papercut\{Component, Node, Text};

/** One entry of a ChoiceList: its label and whether it is marked. */
final readonly class ChoiceOption
{
    public function __construct(
        public Node|Component $label,
        public bool $selected = false,
    ) {
    }

    public static function of(string|Node|Component $label, bool $selected = false): self
    {
        return new self(is_string($label) ? Text::make($label) : $label, $selected);
    }
}
