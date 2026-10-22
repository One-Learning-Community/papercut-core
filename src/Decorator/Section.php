<?php

declare(strict_types=1);

namespace Papercut\Decorator;

use Papercut\{Component, Node, Text, View};
use Papercut\Concerns\HasStyle;
use Papercut\Style\Direction;

/**
 * A heading kept with its content, with an optional number and rule.
 */
final class Section implements Decorator
{
    use HasStyle;

    /** @var list<Node|Component> */
    private array $children;
    private Node|Component|string|null $title = null;
    private string|int|null $number = null;
    private bool $rule = false;

    private function __construct()
    {
    }

    public static function make(Node|Component|string ...$children): self
    {
        $section = new self();
        $section->children = View::normalize($children);
        return $section;
    }

    public function title(string|Node|Component $title): self
    {
        $clone = clone $this;
        $clone->title = is_string($title) && trim($title) === '' ? null : $title;
        return $clone;
    }

    public function number(string|int $n): self
    {
        $clone = clone $this;
        $clone->number = is_string($n) && trim($n) === '' ? null : $n;
        return $clone;
    }

    public function rule(bool $on = true): self
    {
        $clone = clone $this;
        $clone->rule = $on;
        return $clone;
    }

    public function render(): View
    {
        $parts = [];
        if ($this->title !== null || $this->number !== null) {
            $heading = [];
            if ($this->number !== null) {
                $heading[] = Text::make((string) $this->number)->apply('section.number');
            }
            if ($this->title !== null) {
                $heading[] = is_string($this->title)
                    ? Text::make($this->title)->apply('section.title')->grow(1)
                    : View::make($this->title)->apply('section.title')->grow(1);
            }
            $parts[] = View::make(...$heading)
                ->direction(Direction::Row)
                ->apply($this->rule ? 'section.heading section.rule' : 'section.heading')
                ->keepWithNext(true);
        }
        $parts[] = View::make(...$this->children)->apply('section.body');

        return View::make(...$parts)
            ->htmlTag('section')
            ->apply(trim('section ' . ($this->styleName() ?? '')))
            ->apply($this->style());
    }
}
