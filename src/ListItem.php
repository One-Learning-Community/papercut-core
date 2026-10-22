<?php

declare(strict_types=1);

namespace Papercut;

use Papercut\Concerns\HasStyle;

/**
 * One item of a ListNode; its children lay out like a column View beside the marker.
 */
final class ListItem implements Styled
{
    use HasStyle;

    /** @var list<Node|Component> */
    private array $children = [];
    private ?int $value = null;

    public static function make(Node|Component|string ...$children): self
    {
        $item = new self();
        $item->children = View::normalize($children);
        return $item;
    }

    /**
     * @return list<Node|Component>
     */
    public function children(): array
    {
        return $this->children;
    }

    public function withValue(int $n): self
    {
        $clone = clone $this;
        $clone->value = $n;
        return $clone;
    }

    public function valueOverride(): ?int
    {
        return $this->value;
    }

    public function debugName(): string
    {
        return 'item';
    }
}
