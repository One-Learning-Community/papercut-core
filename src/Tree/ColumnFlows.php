<?php

declare(strict_types=1);

namespace Papercut\Tree;

use Papercut\{Node, TableCell, View};
use Papercut\Exception\InvalidStructure;
use Papercut\Style\{Align, ComputedStyle, Direction};
use Papercut\Value\Auto;

/**
 * Placement rules for ColumnFlow, shared by layout, the HTML and Word exports and the builder.
 *
 * Ancestors run from the region root to the direct parent; the region root itself (and the
 * body View the paginator builds) is not in the list.
 *
 * @internal
 * @phpstan-type Ancestors list<array{node: Node, style: ComputedStyle}>
 */
final class ColumnFlows
{
    public static function isFlow(Node $n): bool
    {
        return $n instanceof View && $n->columnSpec() !== null;
    }

    /** A column-direction View that adds nothing a column of text would not have: no box, no size, no side margin, no alignment. */
    public static function isPlainWrapper(Node $n, ComputedStyle $s): bool
    {
        if (!$n instanceof View || $n->columnSpec() !== null || $s->direction !== Direction::Column) {
            return false;
        }
        return self::noEdge($s->borderWidth)
            && $s->background === null
            && self::noEdge($s->padding)
            && $s->margin->left->points === 0.0
            && $s->margin->right->points === 0.0
            && $s->width instanceof Auto
            && $s->height instanceof Auto
            && $s->minWidth === null
            && $s->maxWidth === null
            && $s->minHeight === null
            && $s->maxHeight === null
            && !$s->keepTogether
            && $s->alignItems === Align::Stretch
            && ($s->alignSelf === null || $s->alignSelf === Align::Stretch);
    }

    /** @param Ancestors $ancestors */
    public static function placement(array $ancestors, Region $region): ColumnFlowPlacement
    {
        if ($region !== Region::Body) {
            return ColumnFlowPlacement::OutsideBody;
        }
        foreach ($ancestors as ['node' => $node, 'style' => $style]) {
            if ($node instanceof TableCell) {
                return ColumnFlowPlacement::InCell;
            }
            if ($node instanceof View && $style->direction === Direction::Row) {
                return ColumnFlowPlacement::InRow;
            }
            if (self::isFlow($node)) {
                return ColumnFlowPlacement::InFlow;
            }
        }
        foreach ($ancestors as ['node' => $node, 'style' => $style]) {
            if (!self::isPlainWrapper($node, $style)) {
                return ColumnFlowPlacement::InBox;
            }
        }
        return ColumnFlowPlacement::OnPage;
    }

    /**
     * A break needs a flow above it, with no cell or row between the nearest flow and the break.
     *
     * @param Ancestors $ancestors
     */
    public static function columnBreakAllowed(array $ancestors): bool
    {
        for ($i = count($ancestors) - 1; $i >= 0; $i--) {
            ['node' => $node, 'style' => $style] = $ancestors[$i];
            if (self::isFlow($node)) {
                return true;
            }
            if ($node instanceof TableCell || ($node instanceof View && $style->direction === Direction::Row)) {
                return false;
            }
        }
        return false;
    }

    public static function misplaced(ColumnFlowPlacement $p, string $path): InvalidStructure
    {
        $place = match ($p) {
            ColumnFlowPlacement::InCell => 'inside a table cell',
            ColumnFlowPlacement::InRow => 'inside a row',
            ColumnFlowPlacement::InFlow => 'inside another ColumnFlow',
            ColumnFlowPlacement::OutsideBody => 'in a header, footer, background or overlay',
            ColumnFlowPlacement::OnPage, ColumnFlowPlacement::InBox => 'here',
        };
        return new InvalidStructure(sprintf('A ColumnFlow can\'t go %s (at "%s"). Columns work on the page or inside a box.', $place, $path));
    }

    public static function strayBreak(string $path): InvalidStructure
    {
        return new InvalidStructure(sprintf('A ColumnBreak only works inside a ColumnFlow (at "%s").', $path));
    }

    private static function noEdge(\Papercut\Value\Edges $e): bool
    {
        return $e->top->points === 0.0 && $e->right->points === 0.0 && $e->bottom->points === 0.0 && $e->left->points === 0.0;
    }
}
