<?php

declare(strict_types=1);

namespace Papercut;

use Papercut\Style\{Anchor, Style};
use Papercut\Support\EnumValue;
use Papercut\Value\{Length, Percent};

/**
 * Content placed at a point on the page, for Page::overlay(). x and y are lengths from the page's
 * top-left corner, or percentages of its width and height, and the anchor says which point of the
 * content sits there. Without a width the content is as wide as it needs, up to the page width;
 * percentages inside it are of the place's width.
 */
final class Place implements Node
{
    private Anchor $anchor = Anchor::TopLeft;
    private Length|Percent|null $width = null;

    /** @param list<Node|Component> $children */
    private function __construct(
        private readonly Length|Percent $x,
        private readonly Length|Percent $y,
        private readonly array $children,
    ) {
    }

    /** @throws \Papercut\Exception\InvalidValue for a length that is not one, such as "12" without a unit */
    public static function at(Length|Percent|string $x, Length|Percent|string $y, Node|Component|string ...$nodes): self
    {
        return new self(self::parse($x), self::parse($y), View::normalize($nodes));
    }

    /** @throws \Papercut\Exception\InvalidValue for a string that is not an anchor */
    public function anchor(Anchor|string $anchor): self
    {
        $anchor = EnumValue::from(Anchor::class, $anchor);
        $clone = clone $this;
        $clone->anchor = $anchor;
        return $clone;
    }

    /** A length, or a percentage of the page width. */
    public function width(Length|Percent|string $width): self
    {
        $clone = clone $this;
        $clone->width = self::parse($width);
        return $clone;
    }

    public function x(): Length|Percent
    {
        return $this->x;
    }

    public function y(): Length|Percent
    {
        return $this->y;
    }

    public function anchorPoint(): Anchor
    {
        return $this->anchor;
    }

    public function boxWidth(): Length|Percent|null
    {
        return $this->width;
    }

    /** @return list<Node|Component> */
    public function children(): array
    {
        return $this->children;
    }

    /** A place has no style of its own; style its content instead. */
    public function style(): Style
    {
        return new Style();
    }

    public function debugName(): string
    {
        return 'place';
    }

    private static function parse(Length|Percent|string $value): Length|Percent
    {
        if (!is_string($value)) {
            return $value;
        }
        return str_ends_with(trim($value), '%') ? Percent::parse($value) : Length::parse($value);
    }
}
