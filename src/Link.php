<?php

declare(strict_types=1);

namespace Papercut;

use Papercut\Concerns\HasStyle;

/**
 * A hyperlinked block; lays out like a column View.
 */
final class Link implements Styled
{
    use HasStyle;

    /**
     * @param list<Node|Component> $children
     */
    private function __construct(private readonly string $uri, private readonly array $children)
    {
    }

    public static function to(string $uri, Node|Component|string ...$children): self
    {
        return new self($uri, View::normalize($children));
    }

    public function uri(): string
    {
        return $this->uri;
    }

    /**
     * @return list<Node|Component>
     */
    public function children(): array
    {
        return $this->children;
    }

    public function debugName(): string
    {
        return 'link';
    }
}
