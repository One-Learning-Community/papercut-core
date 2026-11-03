<?php

declare(strict_types=1);

namespace Papercut\Decorator;

use Papercut\{Component, Node, Text, View};
use Papercut\Concerns\{HasStyle, HasVariants};
use Papercut\Exception\{InvalidStructure, InvalidValue};
use Papercut\Style\Direction;
use Papercut\Toc\HeadingText;

/**
 * A heading kept with its content, with an optional number and rule.
 */
final class Section implements Decorator
{
    use HasStyle;
    use HasVariants;

    /** @var list<Node|Component> */
    private array $children;
    private Node|Component|string|null $title = null;
    private string|int|null $number = null;
    private bool $rule = false;
    private ?int $heading = null;

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

    /**
     * Makes the title a heading at $level (1 to 4): a bookmark, a table of contents entry and a Word Heading style.
     * It keeps the section title's look. With a number, the entry and bookmark read "2 Title". Null turns it off.
     */
    public function heading(?int $level): self
    {
        if ($level !== null && ($level < 1 || $level > 4)) {
            throw InvalidValue::for('section heading level', (string) $level)->because('levels are 1 to 4');
        }
        $clone = clone $this;
        $clone->heading = $level;
        return $clone;
    }

    public function rule(bool $on = true): self
    {
        $clone = clone $this;
        $clone->rule = $on;
        return $clone;
    }

    /** @return list<string> */
    public function partNames(): array
    {
        return ['section', 'section.heading', 'section.rule', 'section.title', 'section.number', 'section.body'];
    }

    /**
     * The title, as a heading Text when heading() is set. A number joins its bookmark and table of contents entry
     * unless the title sets its own.
     *
     * @template T of Node|Component
     * @param T $title
     * @return T|Text
     */
    private function headingTitle(Node|Component $title): Node|Component
    {
        if ($this->heading === null) {
            return $title;
        }
        if (!$title instanceof Text) {
            throw new InvalidStructure('A section title can only be a heading when it is text; this one is ' . ($title instanceof Node ? $title->debugName() : 'a component') . '.');
        }
        $title = $title->asHeading($this->heading);
        if ($this->number !== null && $title->style()->bookmark === null) {
            $title = $title->bookmark(trim($this->number . ' ' . HeadingText::plain($title)));
        }
        return $title;
    }

    public function render(): View
    {
        $parts = [];
        if ($this->title !== null || $this->number !== null) {
            $heading = [];
            if ($this->number !== null) {
                $heading[] = Text::make((string) $this->number)->apply($this->part('section.number'));
            }
            if ($this->title !== null) {
                // A string title is the title Text itself; a node title sits in a box that carries the title style.
                $heading[] = is_string($this->title)
                    ? $this->headingTitle(Text::make($this->title))->apply($this->part('section.title'))->grow(1)
                    : View::make($this->headingTitle($this->title))->apply($this->part('section.title'))->grow(1);
            }
            $parts[] = View::make(...$heading)
                ->direction(Direction::Row)
                ->apply($this->rule ? $this->part('section.heading') . ' ' . $this->part('section.rule') : $this->part('section.heading'))
                ->keepWithNext(true);
        }
        $parts[] = View::make(...$this->children)->apply($this->part('section.body'));

        return View::make(...$parts)
            ->htmlTag('section')
            ->apply(trim($this->part('section') . ' ' . ($this->styleName() ?? '')))
            ->apply($this->style());
    }
}
