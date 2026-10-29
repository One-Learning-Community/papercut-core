<?php

declare(strict_types=1);

namespace Papercut\Layout;

/**
 * Space offered to a node. $height is what is left on the current page
 * (null = unbounded); $fresh means nothing has been placed above this node on
 * this page at any ancestor level; $definiteHeight is a stretch or explicit
 * height the node must fill (on a resumed slice too).
 *
 * A table cell sharing a page slice with its neighbours is also given $fill,
 * the outer height of the slice, which a box that breaks takes in full (so its
 * background and side borders reach the slice bottom), and $open, which says
 * the box's rows go on in the next slice, so it draws no bottom edge even if
 * its own content is done.
 */
final readonly class Constraints
{
    public function __construct(
        public float $width,
        public ?float $height,
        public bool $fresh = true,
        public ?float $definiteHeight = null,
        public ?float $fill = null,
        public bool $open = false,
    ) {
    }

    public function withWidth(float $width): self
    {
        return new self($width, $this->height, $this->fresh, $this->definiteHeight, $this->fill, $this->open);
    }

    public function withHeight(?float $height): self
    {
        return new self($this->width, $height, $this->fresh, $this->definiteHeight, $this->fill, $this->open);
    }

    public function withFresh(bool $fresh): self
    {
        return new self($this->width, $this->height, $fresh, $this->definiteHeight, $this->fill, $this->open);
    }

    public function unbounded(): self
    {
        return $this->withHeight(null);
    }
}
