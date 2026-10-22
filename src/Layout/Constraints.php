<?php

declare(strict_types=1);

namespace Papercut\Layout;

/**
 * Space offered to a node. $height is what is left on the current page
 * (null = unbounded); $fresh means nothing has been placed above this node on
 * this page at any ancestor level; $definiteHeight is a stretch or explicit
 * height the node must fill.
 */
final readonly class Constraints
{
    public function __construct(
        public float $width,
        public ?float $height,
        public bool $fresh = true,
        public ?float $definiteHeight = null,
    ) {
    }

    public function withWidth(float $width): self
    {
        return new self($width, $this->height, $this->fresh, $this->definiteHeight);
    }

    public function withHeight(?float $height): self
    {
        return new self($this->width, $height, $this->fresh, $this->definiteHeight);
    }

    public function withFresh(bool $fresh): self
    {
        return new self($this->width, $this->height, $fresh, $this->definiteHeight);
    }

    public function unbounded(): self
    {
        return $this->withHeight(null);
    }
}
