<?php

declare(strict_types=1);

namespace Papercut\Decorator;

use Papercut\{Component, Node, Text};

/** One row of a KeyValueList or Totals: its label, its value, and whether it is an emphasised total row (Totals only). */
final readonly class KeyValuePair
{
    public function __construct(
        public Node|Component $label,
        public Node|Component $value,
        public bool $total = false,
    ) {
    }

    public static function of(string|Node|Component $label, string|Node|Component $value, bool $total = false): self
    {
        return new self(
            is_string($label) ? Text::make($label) : $label,
            is_string($value) ? Text::make($value) : $value,
            $total,
        );
    }
}
