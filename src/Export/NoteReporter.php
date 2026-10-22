<?php

declare(strict_types=1);

namespace Papercut\Export;

use Papercut\{Annotated, Document, Node, Page};
use Papercut\Style\{ComputedStyle, StyleResolver};
use Papercut\Tree\{Region, TreeVisitor, TreeWalker, Visit};

/** Reports every conversion note in the tree through Fidelity, once per export. */
final class NoteReporter implements TreeVisitor
{
    private function __construct(private readonly Fidelity $fidelity)
    {
    }

    public static function report(Document $doc, StyleResolver $styles, Fidelity $fidelity): void
    {
        (new TreeWalker($styles))->walk($doc, new self($fidelity));
    }

    public function enter(Node $node, ComputedStyle $style, Visit $at): bool
    {
        if ($node instanceof Annotated) {
            foreach ($node->notes() as $note) {
                $path = $note->where === '' ? $at->path() : $at->path() . ' > ' . $note->where;
                match ($note->level) {
                    FidelityLevel::Approximated => $this->fidelity->approximated($note->feature, $path),
                    FidelityLevel::Dropped => $this->fidelity->dropped($note->feature, $path),
                };
            }
        }
        return true;
    }

    public function leave(Node $node, ComputedStyle $style, Visit $at): void
    {
    }

    public function enterPage(Page $page, int $index): void
    {
    }

    public function leavePage(Page $page, int $index): void
    {
    }

    public function enterRegion(Region $region, ComputedStyle $root): void
    {
    }

    public function leaveRegion(Region $region): void
    {
    }
}
