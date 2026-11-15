<?php

declare(strict_types=1);

namespace Papercut;

use Papercut\Concerns\HasStyle;
use Papercut\Exception\InvalidValue;
use Papercut\Paint\Link as PaintLink;

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

    /** @throws InvalidValue for a link with a control character, or a scheme other than http, https, mailto, tel or a #fragment */
    public static function to(string $uri, Node|Component|string ...$children): self
    {
        return new self(PaintLink::checked($uri, 'Link::to()'), View::normalize($children));
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
