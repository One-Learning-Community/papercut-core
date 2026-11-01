<?php

declare(strict_types=1);

namespace Papercut\Decorator;

use Papercut\{Component, Node, Table, TableCell, View};
use Papercut\Concerns\{HasStyle, HasVariants};
use Papercut\Value\{Auto, Fr};

/**
 * Label and amount rows with the amounts right-aligned, such as an invoice's subtotal, tax and total.
 * Total rows are emphasised. Rendered as a borderless two-column Table, like KeyValueList.
 */
final class Totals implements Decorator
{
    use HasStyle;
    use HasVariants;

    /** @var list<KeyValuePair> */
    private array $pairs;

    private function __construct()
    {
    }

    public static function make(KeyValuePair ...$pairs): self
    {
        $totals = new self();
        $totals->pairs = array_values($pairs);
        return $totals;
    }

    /** Adds rows; a pair made with `total: true` is a total row. */
    public function add(KeyValuePair ...$pairs): self
    {
        $clone = clone $this;
        $clone->pairs = [...$this->pairs, ...array_values($pairs)];
        return $clone;
    }

    public function row(string|Node|Component $label, string|Node|Component $amount): self
    {
        return $this->add(KeyValuePair::of($label, $amount));
    }

    /** Adds an emphasised row, such as the amount due. Any row can be one, so several are fine. */
    public function total(string|Node|Component $label, string|Node|Component $amount): self
    {
        return $this->add(KeyValuePair::of($label, $amount, total: true));
    }

    /** @return list<KeyValuePair> */
    public function pairs(): array
    {
        return $this->pairs;
    }

    /** @return list<string> */
    public function partNames(): array
    {
        return ['totals', 'totals.label', 'totals.value', 'totals.total-label', 'totals.total-value'];
    }

    public function render(): Table|View
    {
        $names = trim($this->part('totals') . ' ' . ($this->styleName() ?? ''));
        if ($this->pairs === []) {
            return View::make()->apply($names)->apply($this->style());
        }

        $table = Table::make()->columns(Fr::of(1), Auto::instance());
        foreach ($this->pairs as $pair) {
            $label = $this->part('totals.label');
            $value = $this->part('totals.value');
            if ($pair->total) {
                $label .= ' ' . $this->part('totals.total-label');
                $value .= ' ' . $this->part('totals.total-value');
            }
            $table = $table->row(TableCell::make($pair->label)->apply($label), TableCell::make($pair->value)->apply($value));
        }

        return $table->apply($names)->apply($this->style());
    }
}
