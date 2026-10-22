<?php

declare(strict_types=1);

namespace Papercut\Tree;

use Papercut\{Node, Page};
use Papercut\Style\ComputedStyle;

interface TreeVisitor
{
    public function enterPage(Page $page, int $index): void;

    public function leavePage(Page $page, int $index): void;

    public function enterRegion(Region $region, ComputedStyle $root): void;

    public function leaveRegion(Region $region): void;

    /**
     * Return false to skip this node's children. leave() is still called for
     * the node, so every enter() is matched by exactly one leave().
     */
    public function enter(Node $node, ComputedStyle $style, Visit $at): bool;

    public function leave(Node $node, ComputedStyle $style, Visit $at): void;
}
