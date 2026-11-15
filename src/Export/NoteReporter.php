<?php

declare(strict_types=1);

namespace Papercut\Export;

use Papercut\{Annotated, Document, Equation, Node, Page, PageBreak, Table, Text, View};
use Papercut\Layout\TableGrid;
use Papercut\Markup\{MathNotes, Note};
use Papercut\Style\{ComputedStyle, Direction, PageThemes, StyleResolver};
use Papercut\Tree\{Region, TreeVisitor, TreeWalker, Visit};

/**
 * Reports every conversion note in the tree through Fidelity, once per export.
 *
 * @internal Part of Papercut's engine, not its public API. It may change in any release.
 */
final class NoteReporter implements TreeVisitor
{
    /** A colspan the grid cut at its last column: in every format, since each lays the columns out from the same grid. */
    public const string COLSPAN_CLAMPED = 'table cell colspan runs past column ' . TableGrid::MAX_COLUMNS . '; clamped';

    private function __construct(private readonly Fidelity $fidelity)
    {
    }

    public static function report(Document $doc, StyleResolver|PageThemes $styles, Fidelity $fidelity): void
    {
        (new TreeWalker($styles))->walk($doc, new self($fidelity));
    }

    public function enter(Node $node, ComputedStyle $style, Visit $at): bool
    {
        if ($node instanceof Annotated) {
            $this->reportAll($node->notes(), $at);
        }
        if ($node instanceof PageBreak && self::inRow($at)) {
            $this->fidelity->approximated('page break inside a row', $at->path());
        }
        if ($node instanceof Table && TableGrid::of($node)->clampedWide !== []) {
            $this->fidelity->approximated(self::COLSPAN_CLAMPED, $at->path());
        }
        if ($node instanceof Equation) {
            $this->reportAll(MathNotes::of($node->math()), $at);
        }
        if ($node instanceof Text) {
            foreach ($node->spanList() as $span) {
                if ($span->math !== null) {
                    $this->reportAll(MathNotes::of($span->math), $at);
                }
            }
        }
        return true;
    }

    /**
     * A page break whose parent is a row has no page to end: the PDF leaves it out, Word
     * never wrote it, and the HTML writer's forced break is ignored by browsers inside a flex row, so every format reports it.
     */
    private static function inRow(Visit $at): bool
    {
        $parent = $at->ancestors()[count($at->ancestors()) - 1] ?? null;

        return $parent !== null && $parent['node'] instanceof View && $parent['style']->direction === Direction::Row;
    }

    /** @param iterable<Note> $notes */
    private function reportAll(iterable $notes, Visit $at): void
    {
        foreach ($notes as $note) {
            $path = $note->where === '' ? $at->path() : $at->path() . ' > ' . $note->where;
            match ($note->level) {
                FidelityLevel::Approximated => $this->fidelity->approximated($note->feature, $path),
                FidelityLevel::Dropped => $this->fidelity->dropped($note->feature, $path),
            };
        }
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
