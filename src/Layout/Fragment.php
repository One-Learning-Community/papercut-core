<?php

declare(strict_types=1);

namespace Papercut\Layout;

use Papercut\Paint\{Op, PageNumberSlot};

/**
 * A laid-out box in local coordinates (top-left origin). Mutable only while
 * the layouter that created it builds it: once returned from layout() it is
 * immutable. Never mutate a fragment you did not create; the paginator
 * reuses header and footer fragments across pages.
 */
final class Fragment
{
    /** @var list<Op> */
    private array $ops = [];
    /** @var list<array{x: float, y: float, fragment: Fragment}> */
    private array $children = [];
    /** @var list<PageNumberSlot> */
    private array $slots = [];

    public ?float $opacity = null;
    /** @var ?array{float, float, float, float} local x, y, width, height */
    public ?array $clip = null;

    public function __construct(public float $width, public float $height)
    {
    }

    public function addOp(Op $op): void
    {
        $this->ops[] = $op;
    }

    public function addChild(float $x, float $y, Fragment $f): void
    {
        $this->children[] = ['x' => $x, 'y' => $y, 'fragment' => $f];
    }

    public function addSlot(PageNumberSlot $s): void
    {
        $this->slots[] = $s;
    }

    /** @return list<Op> */
    public function ops(): array
    {
        return $this->ops;
    }

    /** @return list<array{x: float, y: float, fragment: Fragment}> */
    public function children(): array
    {
        return $this->children;
    }

    /** @return list<PageNumberSlot> */
    public function slots(): array
    {
        return $this->slots;
    }
}
