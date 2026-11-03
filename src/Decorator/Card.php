<?php

declare(strict_types=1);

namespace Papercut\Decorator;

use Papercut\{Component, Node, Text, View};
use Papercut\Concerns\{HasStyle, HasVariants};
use Papercut\Exception\{InvalidStructure, InvalidValue};

/**
 * A bordered, padded box with an optional title strip across the top.
 */
final class Card implements Decorator
{
    use HasStyle;
    use HasVariants;

    /** @var list<Node|Component> */
    private array $children;
    private Node|Component|null $title = null;
    private ?int $heading = null;

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

    /**
     * Makes the title a heading at $level (1 to 4): a bookmark, a table of contents entry and a Word Heading style.
     * It keeps the card title's look. Null turns it off.
     */
    public function heading(?int $level): self
    {
        if ($level !== null && ($level < 1 || $level > 4)) {
            throw InvalidValue::for('card heading level', (string) $level)->because('levels are 1 to 4');
        }
        $clone = clone $this;
        $clone->heading = $level;
        return $clone;
    }

    /** @return list<string> */
    public function partNames(): array
    {
        return ['card', 'card.title', 'card.body'];
    }

    public function render(): View
    {
        $parts = [];
        if ($this->title !== null) {
            $title = $this->title;
            if ($this->heading !== null) {
                if (!$title instanceof Text) {
                    throw new InvalidStructure('A card title can only be a heading when it is text; this one is ' . ($title instanceof Node ? $title->debugName() : 'a component') . '.');
                }
                $title = $title->asHeading($this->heading);
            }
            $parts[] = View::make($title)->apply($this->part('card.title'))->keepWithNext(true);
        }
        $parts[] = View::make(...$this->children)->apply($this->part('card.body'));

        return View::make(...$parts)
            ->apply(trim($this->part('card') . ' ' . ($this->styleName() ?? '')))
            ->apply($this->style());
    }
}
