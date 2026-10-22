<?php

declare(strict_types=1);

namespace Papercut\Decorator;

use Papercut\{Component, Node, Text, View};
use Papercut\Concerns\HasStyle;

/**
 * A bordered, padded box with an optional title strip across the top.
 */
final class Card implements Decorator
{
    use HasStyle;

    /** @var list<Node|Component> */
    private array $children;
    private Node|Component|null $title = null;

    private function __construct()
    {
    }

    public static function make(Node|Component|string ...$children): self
    {
        $card = new self();
        $card->children = View::normalize($children);
        return $card;
    }

    public function title(string|Node|Component $title): self
    {
        $clone = clone $this;
        $clone->title = match (true) {
            !is_string($title) => $title,
            trim($title) === '' => null,
            default => Text::make($title),
        };
        return $clone;
    }

    public function render(): View
    {
        $parts = [];
        if ($this->title !== null) {
            $parts[] = View::make($this->title)->apply('card.title')->keepWithNext(true);
        }
        $parts[] = View::make(...$this->children)->apply('card.body');

        return View::make(...$parts)
            ->apply(trim('card ' . ($this->styleName() ?? '')))
            ->apply($this->style());
    }
}
