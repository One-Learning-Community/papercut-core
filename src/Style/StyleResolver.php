<?php

declare(strict_types=1);

namespace Papercut\Style;

use Papercut\{Component, Link, ListItem, ListNode, Node, Place, Styled, TableCell, Text, ThemedComponent, Toc as TocNode, View};
use Papercut\Tree\Expander;
use Papercut\Value\Edges;

use function Papercut\pt;

/**
 * Resolves a node's computed style from the theme. Independent of layout, so
 * every exporter resolves styles identically.
 *
 * @internal Part of Papercut's engine, not its public API. It may change in any release.
 */
final class StyleResolver
{
    private ?ComputedStyle $root = null;
    /** Computed styles per node and parent. Nodes are immutable, so a node under the same parent always resolves the same. */
    private StyleMemo $styles;
    /** The same for cells resolved under a table row, with the theme's cell defaults. */
    private StyleMemo $cellStyles;
    /**
     * One instance per distinct computed style, by its serialized form. A long table resolves
     * thousands of nodes to a handful of styles; each is kept once.
     *
     * @var array<string, ComputedStyle>
     */
    private array $interned = [];
    /**
     * Each parent's computed children, by the child's list flag and serialized style. A child's computed
     * style depends on nothing else (ComputedStyle::child()), and most nodes share a handful of styles, so
     * each is computed once. Only parents this resolver keeps anyway have an entry: the root and the interned
     * styles. Layout builds throwaway parents to measure with, and remembering those would keep them alive.
     *
     * @var \SplObjectStorage<ComputedStyle, array<string, ComputedStyle>>
     */
    private \SplObjectStorage $children;
    /** @var \SplObjectStorage<TableCell, TableCell> layout's prepared copies of table cells, each to the cell it copies */
    private \SplObjectStorage $copies;
    /** @var \SplObjectStorage<View, true> the columns layout wraps a page region's nodes in */
    private \SplObjectStorage $wrappers;
    /** @var array<int, Style> a cell's base style, by whether it is a header cell */
    private array $cellBase = [];
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
        $this->styles = new StyleMemo();
        $this->cellStyles = new StyleMemo();
        $this->copies = new \SplObjectStorage();
        $this->wrappers = new \SplObjectStorage();
        $this->children = new \SplObjectStorage();
    }

    /**
     * Registers the column layout wraps a page region's nodes in (the tree walk visits them under the
     * root style directly). When its computed style equals its parent's, it resolves to the parent itself,
     * so the nodes in it share the styles the walk already resolved instead of computing them again.
     *
     * @internal Paginator only.
     */
    public function wrapper(View $column): void
    {
        $this->wrappers[$column] = true;
    }

    /**
     * Registers layout's copy of a cell with the theme's cell defaults applied (TableLayouter::prepare()).
     * The copy then resolves as the cell itself does under its row, the same style either way, so it
     * and everything in it share the styles the tree walk already resolved instead of computing them again.
     *
     * @internal TableLayouter only.
     */
    public function copyOf(TableCell $copy, TableCell $cell): void
    {
        $this->copies[$copy] = $cell;
    }

    /** The theme this resolver resolves against. */
    public function theme(): Theme
    {
        return $this->theme;
    }

    /** The theme's root style, computed once so memoized child styles are shared across pages. */
    public function root(): ComputedStyle
    {
        if ($this->root === null) {
            $this->root = ComputedStyle::root($this->theme);
            $this->children[$this->root] = [];
        }
        return $this->root;
    }

    /**
     * Theme preset (Text h1-h4/p), then the named theme style, then the
     * node's own style, all applied on top of the parent's computed style.
     */
    public function resolve(Node $node, ComputedStyle $parent): ComputedStyle
    {
        if ($node instanceof TableCell && isset($this->copies[$node])) {
            return $this->resolveInTable($this->copies[$node], $parent);
        }
        return $this->styles->get($node, $parent) ?? $this->styles->put($node, $parent, $this->compute($node, $parent));
    }

    /**
     * Like resolve(), for a cell under a table row: the theme's cell padding
     * and bold th weight form the lowest layer. TreeWalker calls this for
     * cells under a table row; layout applies the same defaults through
     * TableLayouter::prepare(). A bare cell outside a table uses resolve().
     */
    public function resolveInTable(TableCell $cell, ComputedStyle $rowStyle): ComputedStyle
    {
        $cached = $this->cellStyles->get($cell, $rowStyle);
        if ($cached !== null) {
            return $cached;
        }
        $header = $cell->header();
        // Two shared base styles, one for header cells and one for body cells.
        $base = $this->cellBase[(int) $header] ??= new Style(padding: $this->theme->cellPadding, fontWeight: $header ? 700 : null);
        return $this->cellStyles->put($cell, $rowStyle, $this->compute($cell, $rowStyle, $base, false));
    }

    /** The one instance of this computed style (see $interned). */
    private function intern(ComputedStyle $style): ComputedStyle
    {
        $key = serialize($style);
        if (!isset($this->interned[$key])) {
            $this->interned[$key] = $style;
            $this->children[$style] = [];
        }
        return $this->interned[$key];
    }

    private function compute(Node $node, ComputedStyle $parent, ?Style $style = null, ?bool $inList = null): ComputedStyle
    {
        $style ??= Style::none();
        if ($node instanceof Text && $node->preset() !== null) {
            $preset = $node->preset();
            $style = $style->merge($preset === 'p' ? $this->theme->paragraph() : $this->theme->heading((int) substr($preset, 1)));
            if ($preset !== 'p') {
                $style = $style->merge($this->theme->named($preset . '?'));
            }
        }
        if ($node instanceof TocNode) {
            $style = $style->merge($this->theme->named('toc'));
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
        } elseif ($node instanceof TableCell) {
            // A table cell starts outside any list, however it is resolved (in a row, or as a copy layout pins to its column).
            $inList = false;
        }
        $computed = $this->child($parent, $style, $inList);
        if ($node instanceof View && isset($this->wrappers[$node]) && $computed == $parent) {
            return $parent;
        }
        $this->noteLastChild($node, $computed);
        if ($node instanceof ListNode && !$parent->inList && $style->margin === null && !isset($this->endsContainer[$node])) {
            return $this->intern($this->withListSpacing($node, $computed));
        }
        return $computed;
    }

    /** A span's computed style in a paragraph with computed style $text; $own is the span's style with its theme style applied (Theme::spanStyle()). */
    public function span(ComputedStyle $text, Style $own): ComputedStyle
    {
        return $this->child($text, $own, null, false);
    }

    /**
     * $parent->child($style, $inList), computed once per parent, list flag and style (see $children), and interned.
     * A parent the resolver did not intern is not remembered: its child is computed, and interned only if $intern.
     */
    private function child(ComputedStyle $parent, Style $style, ?bool $inList, bool $intern = true): ComputedStyle
    {
        if (!isset($this->children[$parent])) {
            $computed = $parent->child($style, $inList);
            return $intern ? $this->intern($computed) : $computed;
        }
        $key = ($inList === null ? 'n' : ($inList ? 't' : 'f')) . serialize($style);
        $memo = $this->children[$parent];
        if (isset($memo[$key])) {
            return $memo[$key];
        }
        $memo[$key] = $computed = $this->intern($parent->child($style, $inList));
        $this->children[$parent] = $memo;
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
        $last = $this->nodeOf($children[array_key_last($children)]);
        if ($last !== null) {
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
        $child = $this->nodeOf($children[array_key_last($children)]);
        if ($child === null) {
            return 0.0;
        }
        $childStyle = $this->resolve($child, $style);
        $own = $childStyle->margin->bottom->points;
        return $own + (($child instanceof View || $child instanceof ListItem || $child instanceof ListNode || $child instanceof Link) ? $this->trailingMargin($child, $childStyle) : 0.0);
    }

    /**
     * The node itself, or a themed component expanded with this theme (those memoize per theme, so
     * the layout gets the same node); other components are left alone, as expanding them here would render them an extra time.
     */
    private function nodeOf(Node|Component $child): ?Node
    {
        if ($child instanceof Node) {
            return $child;
        }
        return $child instanceof ThemedComponent ? Expander::expand($child, $this->theme) : null;
    }
}
