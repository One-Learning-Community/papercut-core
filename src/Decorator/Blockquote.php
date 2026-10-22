<?php

declare(strict_types=1);

namespace Papercut\Decorator;

use Papercut\{Component, Node, Text, View};
use Papercut\Concerns\HasStyle;
use Papercut\Style\Direction;

/**
 * A quotation with a leading rule and an optional attribution line.
 */
final class Blockquote implements Decorator
{
    use HasStyle;

    /** @var list<Node|Component> */
    private array $children;
    private Node|Component|string|null $cite = null;

    private function __construct()
    {
    }

    public static function make(Node|Component|string ...$children): self
    {
        $quote = new self();
        $quote->children = View::normalize($children);
        return $quote;
    }

    public function cite(string|Node|Component $cite): self
    {
        $clone = clone $this;
        $clone->cite = is_string($cite) && trim($cite) === '' ? null : $cite;
        return $clone;
    }

    public function render(): View
    {
        $parts = $this->children;
        if (is_string($this->cite)) {
            $parts[] = Text::make("\u{2014} " . $this->cite)->apply('blockquote.cite');
        } elseif ($this->cite !== null) {
            $parts[] = View::make(Text::make("\u{2014} "), $this->cite)
                ->direction(Direction::Row)
                ->apply('blockquote.cite');
        }

        return View::make(...$parts)
            ->htmlTag('blockquote')
            ->apply(trim('blockquote ' . ($this->styleName() ?? '')))
            ->apply($this->style());
    }
}
