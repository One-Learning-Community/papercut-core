<?php

declare(strict_types=1);

namespace Papercut\Style;

use Papercut\{Link, ListItem, ListNode, Node, Place, Styled, TableCell, Text, View};
use Papercut\Value\Edges;

use function Papercut\pt;

/**
 * Resolves a node's computed style from the theme. Independent of layout, so
 * every exporter resolves styles identically.
 */
final class StyleResolver
{
    private ?ComputedStyle $root = null;
    /**
     * Computed styles memoized per node and parent style (both by identity).
     * Nodes are immutable, so a node under the same parent always resolves the same.
     *
     * @var \WeakMap<Node, \WeakMap<ComputedStyle, ComputedStyle>>
     */
    private \WeakMap $styles;
    /** @var \WeakMap<TableCell, \WeakMap<ComputedStyle, ComputedStyle>> */
    private \WeakMap $cellStyles;
    /**
     * Nodes that end a container whose bottom edge shows (a table cell, or a box with bottom
     * padding, a bottom border or a background), directly or through transparent wrappers
     * that are themselves last. Recorded when the container is resolved; a container resolves
     * before its children, since a child resolves against its computed style. The note lasts
     * as long as the resolver: a node reused where something follows it keeps the note, and
     * a style already cached for it under a parent keeps whichever spacing it was first
     * resolved with.
     *
     * @var \WeakMap<Node, true>
     */
    private \WeakMap $endsContainer;

    public function __construct(private readonly Theme $theme)
    {
        $this->endsContainer = new \WeakMap();
        $this->styles = new \WeakMap();
        $this->cellStyles = new \WeakMap();
    }

    /** The theme's root style, computed once so memoized child styles are shared across pages. */
    public function root(): ComputedStyle
    {
        return $this->root ??= ComputedStyle::root($this->theme);
    }

    /**
     * Theme preset (Text h1-h4/p), then the named theme style, then the
     * node's own style, all applied on top of the parent's computed style.
     */
    public function resolve(Node $node, ComputedStyle $parent): ComputedStyle
    {
        $byParent = $this->styles[$node] ?? null;
        if ($byParent === null) {
            /** @var \WeakMap<ComputedStyle, ComputedStyle> $byParent */
            $byParent = new \WeakMap();
            $this->styles[$node] = $byParent;
        }
        return $byParent[$parent] ??= $this->compute($node, $parent);
    }

    /**
     * Like resolve(), for a cell under a table row: the theme's cell padding
     * and bold th weight form the lowest layer. TreeWalker calls this for
     * cells under a table row; layout applies the same defaults through
     * TableLayouter::prepare(). A bare cell outside a table uses resolve().
     */
    public function resolveInTable(TableCell $cell, ComputedStyle $rowStyle): ComputedStyle
    {
        $byParent = $this->cellStyles[$cell] ?? null;
        if ($byParent === null) {
            /** @var \WeakMap<ComputedStyle, ComputedStyle> $byParent */
            $byParent = new \WeakMap();
            $this->cellStyles[$cell] = $byParent;
        }
        return $byParent[$rowStyle] ??= $this->compute(
            $cell,
            $rowStyle,
            new Style(padding: $this->theme->cellPadding, fontWeight: $cell->header() ? 700 : null),
            false,
        );
    }

    private function compute(Node $node, ComputedStyle $parent, Style $style = new Style(), ?bool $inList = null): ComputedStyle
    {
        if ($node instanceof Text && $node->preset() !== null) {
            $preset = $node->preset();
            $style = $style->merge($preset === 'p' ? $this->theme->paragraph() : $this->theme->heading((int) substr($preset, 1)));
        }
        if ($node instanceof Styled) {
            $name = $node->styleName();
            if (is_string($name)) {
                $style = $style->merge($this->theme->named($name));
            }
        }
        $style = $style->merge($node->style());
        if ($node instanceof ListEntry) {
            $inList = true;
        }
        $computed = $parent->child($style, $inList);
        $this->noteLastChild($node, $computed);
        if ($node instanceof ListNode && !$parent->inList && $style->margin === null && !isset($this->endsContainer[$node])) {
            return $this->withListSpacing($node, $computed);
        }
        return $computed;
    }

    /** Records the last child of a container whose bottom edge shows (see endsContainer), so a list ending it adds no spacing. */
    private function noteLastChild(Node $node, ComputedStyle $style): void
    {
        if (!($node instanceof View || $node instanceof TableCell || $node instanceof Link || $node instanceof Place)) {
            return;
        }
        $closed = $node instanceof TableCell
            || isset($this->endsContainer[$node])
            || $style->padding->bottom->points > 0.0
            || $style->borderWidth->bottom->points > 0.0
            || ($style->background !== null && !$style->background->isTransparent());
        if (!$closed) {
            return;
        }
        $children = $node->children();
        if ($children === []) {
            return;
        }
        // A component last child is left alone: expanding it here would render it an extra time.
        $last = $children[array_key_last($children)];
        if ($last instanceof Node) {
            $this->endsContainer[$last] = true;
        }
    }

    /** A top-level list's computed style with the theme's list spacing, less what its last item already ends with. */
    private function withListSpacing(ListNode $list, ComputedStyle $base): ComputedStyle
    {
        $items = $list->items();
        $last = end($items);
        $trailing = $last === false ? 0.0 : $this->trailingMargin($last, $this->resolve($last, $base));
        $spacing = $this->theme->list()->margin?->bottom->points ?? 0.0;
        $bottom = max(0.0, $spacing - $trailing);
        $m = $base->margin;
        return $base->withMargin(Edges::of($m->top, $m->right, pt($bottom), $m->left));
    }

    /**
     * Bottom margins along the last-child chain below a container. Stops at leaves and tables.
     */
    private function trailingMargin(Node $container, ComputedStyle $style): float
    {
        $children = match (true) {
            $container instanceof ListNode => $container->items(),
            $container instanceof View, $container instanceof ListItem, $container instanceof Link => $container->children(),
            default => [],
        };
        if ($children === []) {
            return 0.0;
        }
        $child = $children[array_key_last($children)];
        if (!$child instanceof Node) {
            // A component is left alone: expanding it here would render it an extra time.
            return 0.0;
        }
        $childStyle = $this->resolve($child, $style);
        $own = $childStyle->margin->bottom->points;
        return $own + (($child instanceof View || $child instanceof ListItem || $child instanceof ListNode || $child instanceof Link) ? $this->trailingMargin($child, $childStyle) : 0.0);
    }
}
