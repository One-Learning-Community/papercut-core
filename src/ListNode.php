<?php

declare(strict_types=1);

namespace Papercut;

use Papercut\Concerns\HasStyle;
use Papercut\Style\ListMarker;

/**
 * An ordered or unordered list of ListItems.
 */
final class ListNode implements Styled
{
    use HasStyle;

    /**
     * @param list<ListItem> $items
     */
    private function __construct(private readonly bool $ordered, private readonly array $items, private int $start = 1, private ?ListMarker $marker = null)
    {
    }

    public static function ordered(ListItem|Node|Component|string ...$items): self
    {
        return new self(true, self::wrap($items));
    }

    public static function unordered(ListItem|Node|Component|string ...$items): self
    {
        return new self(false, self::wrap($items));
    }

    public function start(int $n): self
    {
        $clone = clone $this;
        $clone->start = $n;
        return $clone;
    }

    public function marker(ListMarker $m): self
    {
        $clone = clone $this;
        $clone->marker = $m;
        return $clone;
    }

    public function isOrdered(): bool
    {
        return $this->ordered;
    }

    public function startNumber(): int
    {
        return $this->start;
    }

    /** Null means the marker follows the nesting depth. */
    public function markerStyle(): ?ListMarker
    {
        return $this->marker;
    }

    /**
     * @return list<ListItem>
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * One number per item: the start, counting up, reset by an item's value
     * override; counting stops at PHP_INT_MAX rather than overflowing.
     *
     * @return list<int>
     */
    public function numbers(): array
    {
        $out = [];
        $n = $this->start;
        foreach ($this->items as $item) {
            $n = $item->valueOverride() ?? $n;
            $out[] = $n;
            $n = $n === PHP_INT_MAX ? $n : $n + 1;
        }
        return $out;
    }

    public function debugName(): string
    {
        return 'list';
    }

    /**
     * @param array<ListItem|Node|Component|string> $items
     * @return list<ListItem>
     */
    private static function wrap(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $out[] = $item instanceof ListItem ? $item : ListItem::make($item);
        }
        return $out;
    }
}
