<?php

declare(strict_types=1);

namespace Papercut\Style;

use Papercut\{Node, Styled, TableCell, Text};

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

    public function __construct(private readonly Theme $theme)
    {
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
        );
    }

    private function compute(Node $node, ComputedStyle $parent, Style $style = new Style()): ComputedStyle
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
        return $parent->child($style->merge($node->style()));
    }
}
