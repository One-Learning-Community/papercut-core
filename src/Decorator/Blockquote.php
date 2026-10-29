<?php

declare(strict_types=1);

namespace Papercut\Decorator;

use Papercut\{Component, Node, Text, View};
use Papercut\Concerns\{HasStyle, HasVariants};
use Papercut\Style\Direction;

/**
 * A quotation with a leading rule and an optional attribution line.
 */
final class Blockquote implements Decorator
{
    use HasStyle;
    use HasVariants;

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

    /** @return list<string> */
    public function partNames(): array
    {
        return ['blockquote', 'blockquote.cite'];
    }

    public function render(): View
    {
        $parts = $this->children;
        if (is_string($this->cite)) {
            $parts[] = Text::make("\u{2014} " . $this->cite)->apply($this->part('blockquote.cite'));
        } elseif ($this->cite !== null) {
            $parts[] = View::make(Text::make("\u{2014} "), $this->cite)
                ->direction(Direction::Row)
                ->apply($this->part('blockquote.cite'));
        }

        return View::make(...$parts)
            ->htmlTag('blockquote')
            ->apply(trim($this->part('blockquote') . ' ' . ($this->styleName() ?? '')))
            ->apply($this->style());
    }
}
