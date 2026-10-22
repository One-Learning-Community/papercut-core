<?php

declare(strict_types=1);

namespace Papercut\Decorator;

use InvalidArgumentException;
use Papercut\{Component, Node, Text, View};
use Papercut\Concerns\HasStyle;

/**
 * A toned aside box (note, tip, warning, danger or a theme-defined tone) with an optional title.
 */
final class Callout implements Decorator
{
    use HasStyle;

    /** @var list<Node|Component> */
    private array $children;
    private string $tone = 'note';
    private Node|Component|string|null $title = null;

    private function __construct()
    {
    }

    public static function make(Node|Component|string ...$children): self
    {
        $callout = new self();
        $callout->children = View::normalize($children);
        return $callout;
    }

    /** Tones are free strings; `callout.<tone>` and `callout.title.<tone>` must exist in the theme. */
    public function tone(string $tone): self
    {
        $tone = trim($tone);
        if ($tone === '') {
            throw new InvalidArgumentException('Callout tone must not be empty.');
        }
        if (preg_match('/\s/', $tone) === 1) {
            throw new InvalidArgumentException("Callout tone must be a single word, got '$tone'.");
        }
        $clone = clone $this;
        $clone->tone = $tone;
        return $clone;
    }

    public function toneName(): string
    {
        return $this->tone;
    }

    public function title(string|Node|Component $title): self
    {
        $clone = clone $this;
        $clone->title = is_string($title) && trim($title) === '' ? null : $title;
        return $clone;
    }

    public function render(): View
    {
        $parts = [];
        if ($this->title !== null) {
            $names = 'callout.title callout.title.' . $this->tone;
            $parts[] = is_string($this->title)
                ? Text::make($this->title)->apply($names)->keepWithNext(true)
                : View::make($this->title)->apply($names)->keepWithNext(true);
        }
        array_push($parts, ...$this->children);

        return View::make(...$parts)
            ->htmlTag('aside')
            ->apply(trim('callout callout.' . $this->tone . ' ' . ($this->styleName() ?? '')))
            ->apply($this->style());
    }
}
