<?php

declare(strict_types=1);

namespace Papercut\Decorator;

use Papercut\{Component, Node, Text, View};
use Papercut\Concerns\{HasStyle, HasVariants};

/**
 * A figure on a tinted panel: a small label, a large value and an optional note, top to bottom.
 */
final class Stat implements Decorator
{
    use HasStyle;
    use HasVariants;

    private Node|Component|string $value;
    private Node|Component|string|null $label = null;
    private Node|Component|string|null $note = null;

    private function __construct()
    {
    }

    public static function make(string|Node|Component $value): self
    {
        $stat = new self();
        $stat->value = $value;
        return $stat;
    }

    /** The small line above the value; a blank string removes it. */
    public function label(string|Node|Component $label): self
    {
        $clone = clone $this;
        $clone->label = is_string($label) && trim($label) === '' ? null : $label;
        return $clone;
    }

    /** The line under the value, such as a change or a comparison; a blank string removes it. */
    public function note(string|Node|Component $note): self
    {
        $clone = clone $this;
        $clone->note = is_string($note) && trim($note) === '' ? null : $note;
        return $clone;
    }

    /** @return list<string> */
    public function partNames(): array
    {
        return ['stat', 'stat.label', 'stat.value', 'stat.note'];
    }

    public function render(): View
    {
        $parts = [];
        if ($this->label !== null) {
            $parts[] = $this->wrap($this->label, 'stat.label');
        }
        $parts[] = $this->wrap($this->value, 'stat.value');
        if ($this->note !== null) {
            $parts[] = $this->wrap($this->note, 'stat.note');
        }

        return View::make(...$parts)
            ->apply(trim($this->part('stat') . ' ' . ($this->styleName() ?? '')))
            ->apply($this->style());
    }

    private function wrap(Node|Component|string $content, string $part): Text|View
    {
        return is_string($content)
            ? Text::make($content)->apply($this->part($part))
            : View::make($content)->apply($this->part($part));
    }
}
