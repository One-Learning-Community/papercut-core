<?php

declare(strict_types=1);

namespace Papercut\Decorator;

use Papercut\{Component, Node, Table, TableCell, View};
use Papercut\Concerns\{HasStyle, HasVariants};
use Papercut\Value\{Auto, Fr, Length, Percent};

/**
 * Aligned "label: value" rows, rendered as a borderless two-column Table.
 */
final class KeyValueList implements Decorator
{
    use HasStyle;
    use HasVariants;

    /** @var list<KeyValuePair> */
    private array $pairs;
    private Length|Percent|null $labelWidth = null;

    private function __construct()
    {
    }

    public static function make(KeyValuePair ...$pairs): self
    {
        $list = new self();
        $list->pairs = array_values($pairs);
        return $list;
    }

    public function add(KeyValuePair ...$pairs): self
    {
        $clone = clone $this;
        $clone->pairs = [...$this->pairs, ...array_values($pairs)];
        return $clone;
    }

    public function row(string|Node|Component $label, string|Node|Component $value): self
    {
        $clone = clone $this;
        $clone->pairs[] = KeyValuePair::of($label, $value);
        return $clone;
    }

    /** Fixes the label column so long labels wrap instead of squeezing the values. */
    public function labelWidth(Length|Percent $width): self
    {
        $clone = clone $this;
        $clone->labelWidth = $width instanceof Length ? $width->absolute('label width') : $width;
        return $clone;
    }

    /** @return list<KeyValuePair> */
    public function pairs(): array
    {
        return $this->pairs;
    }

    /** @return list<string> */
    public function partNames(): array
    {
        return ['key-value', 'key-value.label', 'key-value.value'];
    }

    public function render(): Table|View
    {
        $names = trim($this->part('key-value') . ' ' . ($this->styleName() ?? ''));
        if ($this->pairs === []) {
            return View::make()->apply($names)->apply($this->style());
        }

        $table = Table::make()->columns($this->labelWidth ?? Auto::instance(), Fr::of(1));
        foreach ($this->pairs as $pair) {
            $table = $table->row(
                TableCell::make($pair->label)->apply($this->part('key-value.label')),
                TableCell::make($pair->value)->apply($this->part('key-value.value')),
            );
        }

        return $table->apply($names)->apply($this->style());
    }
}
