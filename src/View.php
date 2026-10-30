<?php

declare(strict_types=1);

namespace Papercut;

use Papercut\Concerns\{HasNotes, HasStyle};
use Papercut\Exception\InvalidValue;
use Papercut\Style\{Align, Direction, Justify, Style};
use Papercut\Support\{EnumValue, Suggest};
use Papercut\Value\Length;

final class View implements Styled, Annotated
{
    use HasStyle;
    use HasNotes;

    /** @var list<Node|Component> */
    private array $children = [];
    private bool $explicitDirection = false;
    private string $htmlTag = 'div';

    private const HTML_TAGS = ['div', 'section', 'aside', 'blockquote', 'header', 'footer', 'figure'];

    private function __construct()
    {
    }

    public static function make(Node|Component|string ...$children): self
    {
        $view = new self();
        $view->style = new Style(direction: Direction::Column);
        $view->children = self::normalize($children);
        return $view;
    }

    /**
     * Wraps string children in Text nodes.
     *
     * @internal
     * @param array<Node|Component|string> $children
     * @return list<Node|Component>
     */
    public static function normalize(array $children): array
    {
        $out = [];
        foreach ($children as $child) {
            $out[] = is_string($child) ? Text::make($child) : $child;
        }
        return $out;
    }

    /**
     * @return list<Node|Component>
     */
    public function children(): array
    {
        return $this->children;
    }

    public function withChildren(Node|Component|string ...$children): self
    {
        $clone = clone $this;
        $clone->children = self::normalize($children);
        return $clone;
    }

    /**
     * Hints the HTML element this view exports as; PDF ignores it.
     */
    public function htmlTag(string $tag): self
    {
        $normalized = strtolower($tag);
        if (!in_array($normalized, self::HTML_TAGS, true)) {
            throw InvalidValue::for('html element', $tag, Suggest::closest($normalized, self::HTML_TAGS));
        }
        $clone = clone $this;
        $clone->htmlTag = $normalized;
        return $clone;
    }

    public function htmlElement(): string
    {
        return $this->htmlTag;
    }

    public function debugName(): string
    {
        if (!$this->explicitDirection) {
            return 'view';
        }
        return $this->style()->direction === Direction::Row ? 'row' : 'column';
    }

    /** @throws InvalidValue for a string that is not a direction */
    public function direction(Direction|string $d): self
    {
        $d = EnumValue::from(Direction::class, $d);
        $clone = $this->withStyle(new Style(direction: $d));
        $clone->explicitDirection = true;
        return $clone;
    }

    public function gap(Length $g): self
    {
        return $this->withStyle(new Style(gap: $g));
    }

    /** @throws InvalidValue for a string that is not a justify option */
    public function justify(Justify|string $j): self
    {
        $j = EnumValue::from(Justify::class, $j);
        return $this->withStyle(new Style(justify: $j));
    }

    /** @throws InvalidValue for a string that is not an alignment */
    public function align(Align|string $a): self
    {
        $a = EnumValue::from(Align::class, $a);
        return $this->withStyle(new Style(alignItems: $a));
    }

    public function wrap(bool $on = true): self
    {
        return $this->withStyle(new Style(wrap: $on));
    }
}
