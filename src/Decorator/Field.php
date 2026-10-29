<?php

declare(strict_types=1);

namespace Papercut\Decorator;

use InvalidArgumentException;
use Papercut\{Component, Node, Text, View};
use Papercut\Concerns\{HasStyle, HasVariants};
use Papercut\Value\Length;


/**
 * A bordered value box with a placeholder when empty and an optional label above.
 *
 * Without a label the box is the outer view and carries the `field` style plus any
 * style name and style set on the decorator. With a label the outer view is an unnamed
 * column that takes those modifiers instead, so they style the whole field; the box
 * inside keeps just `field`. Style the box itself through the theme's `field` style.
 */
final class Field implements Decorator
{
    use HasStyle;
    use HasVariants;

    /** @var list<Node|Component> */
    private array $children;
    private Node|Component|string|null $label = null;
    private ?string $placeholder = null;
    private ?int $lines = null;

    private function __construct()
    {
    }

    public static function make(Node|Component|string ...$children): self
    {
        $field = new self();
        $field->children = View::normalize($children);
        return $field;
    }

    public function label(string|Node|Component $label): self
    {
        $clone = clone $this;
        $clone->label = is_string($label) && trim($label) === '' ? null : $label;
        return $clone;
    }

    /** Shown in the box, in place of the value, while the field is empty. */
    public function placeholder(string $text): self
    {
        $clone = clone $this;
        $clone->placeholder = $text;
        return $clone;
    }

    /** Reserves room for at least this many lines of text. */
    public function lines(int $n): self
    {
        if ($n < 1) {
            throw new InvalidArgumentException('Field lines must be at least 1.');
        }
        $clone = clone $this;
        $clone->lines = $n;
        return $clone;
    }

    /** True when there is nothing to show: no children, or only whitespace-only text. */
    public function isEmpty(): bool
    {
        return self::allEmpty($this->children);
    }

    /** @return list<string> */
    public function partNames(): array
    {
        return ['field', 'field.label', 'field.placeholder'];
    }

    public function render(): View
    {
        $parts = $this->children;
        if ($this->placeholder !== null && $this->isEmpty()) {
            $parts = [Text::make($this->placeholder)->apply($this->part('field.placeholder'))];
        }

        // lines(n) is n lines of the field's own text inside its padding, so it follows the theme's size.
        $box = $this->lines === null
            ? View::make(...$parts)
            : View::make(View::make(...$parts)->minHeight(Length::lh($this->lines)));

        if ($this->label === null) {
            return $box
                ->apply(trim($this->part('field') . ' ' . ($this->styleName() ?? '')))
                ->apply($this->style());
        }

        $label = is_string($this->label)
            ? Text::make($this->label)->apply($this->part('field.label'))->keepWithNext(true)
            : View::make($this->label)->apply($this->part('field.label'))->keepWithNext(true);

        $outer = View::make($label, $box->apply($this->part('field')));
        if ($this->styleName() !== null) {
            $outer = $outer->apply($this->styleName());
        }
        return $outer->apply($this->style());
    }

    /** @param array<Node|Component> $nodes */
    private static function allEmpty(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if ($node instanceof Text) {
                $joined = '';
                foreach ($node->spanList() as $span) {
                    $joined .= $span->lineBreak ? ' ' : $span->text;
                }
                if (trim($joined, " \t\n\r\0\x0B\u{00A0}") !== '') {
                    return false;
                }
            } elseif ($node instanceof View) {
                // Markup::text('') yields an empty View, so a View is empty when everything in it is.
                if (!self::allEmpty($node->children())) {
                    return false;
                }
            } else {
                return false;
            }
        }
        return true;
    }
}
