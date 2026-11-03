<?php

declare(strict_types=1);

namespace Papercut\Tree;

use Papercut\{Component, Document, Link, ListItem, ListNode, Node, Page, Place, Table, TableCell, TableRow, Toc, View};
use Papercut\Exception\{InvalidStructure, InvalidValue, UnknownAttribute};
use Papercut\Style\{ComputedStyle, PageThemes, StyleResolver, Theme};

/**
 * Walks a Document's expanded node tree without laying it out: pages, then
 * regions (background, header, body, footer, overlay), then nodes, with computed styles
 * and paths. Paths match layout's in every region, so errors read the same
 * as from toPdf().
 *
 * @internal Part of Papercut's engine, not its public API. It may change in any release.
 */
final class TreeWalker
{
    private StyleResolver $styles;
    private ?Theme $theme = null;
    private readonly StyleResolver|PageThemes $source;

    /** With PageThemes, each page walks with its own theme's styles. */
    public function __construct(StyleResolver|PageThemes $styles)
    {
        $this->source = $styles;
        $this->styles = $styles instanceof PageThemes ? $styles->documentStyles() : $styles;
    }

    public function walk(Document $doc, TreeVisitor $visitor): void
    {
        $pages = $doc->pages();
        foreach ($pages as $i => $page) {
            $this->styles = $this->source instanceof PageThemes ? $this->source->styles($page) : $this->source;
            $this->theme = $this->source instanceof PageThemes ? $this->source->theme($page) : $this->source->theme();
            $root = $this->styles->root();
            $name = count($pages) > 1 ? sprintf('page[%d]', $i) : 'page';
            $visitor->enterPage($page, $i);
            foreach ($this->regions($page) as [$region, $nodes]) {
                if ($nodes === []) {
                    continue;
                }
                $visitor->enterRegion($region, $root);
                foreach ($nodes as $j => $node) {
                    $this->visit(Expander::expand($node, $this->theme), $root, $name . ' > ' . $region->value, $j, $visitor, $region);
                }
                $visitor->leaveRegion($region);
            }
            $visitor->leavePage($page, $i);
        }
    }

    /** @return list<array{Region, list<Node|Component>}> */
    private function regions(Page $page): array
    {
        return [
            [Region::Background, $page->backgroundNodes()],
            [Region::Header, $page->headerNodes()],
            [Region::FirstHeader, $page->firstHeaderNodes() ?? []],
            [Region::EvenHeader, $page->evenHeaderNodes() ?? []],
            [Region::Body, $page->bodyNodes()],
            [Region::Footer, $page->footerNodes()],
            [Region::FirstFooter, $page->firstFooterNodes() ?? []],
            [Region::EvenFooter, $page->evenFooterNodes() ?? []],
            [Region::Overlay, $page->overlayPlaces()],
        ];
    }

    /**
     * @param string|Visit $parentAt the parent's visit, or the region's path for a top-level node
     * @param list<array{node: Node, style: ComputedStyle}> $ancestors
     */
    private function visit(Node $node, ComputedStyle $parent, string|Visit $parentAt, int $index, TreeVisitor $visitor, Region $region, ?Node $parentNode = null, bool $inHeader = false, ?ComputedStyle $rowStyle = null, array $ancestors = []): void
    {
        try {
            $style = $node instanceof TableCell && $rowStyle !== null
                ? $this->styles->resolveInTable($node, $rowStyle)
                : $this->styles->resolve($node, $parent);
        } catch (UnknownAttribute | InvalidValue $e) {
            $parentPath = $parentAt instanceof Visit ? $parentAt->path() : $parentAt;
            throw $e->within('at ' . $parentPath . ' > ' . NodePath::segment($node, null, $index));
        }
        $at = Visit::under($parentAt, $node, $style, $index, $parentNode, $inHeader, $region, $ancestors);
        if ($node instanceof Place && ($region !== Region::Overlay || $parentNode !== null)) {
            throw new \LogicException(sprintf('A place only works in a page overlay; add it with Page::overlay(), not inside other content (at %s).', $at->path()));
        }
        if ($node instanceof Toc && $region !== Region::Body) {
            throw new InvalidStructure(sprintf('A table of contents only works in a page body (at %s).', $at->path()));
        }

        if ($visitor->enter($node, $style, $at)) {
            $this->children($node, $style, $at, $visitor, $region, $inHeader, [...$ancestors, ['node' => $node, 'style' => $style]]);
        }
        $visitor->leave($node, $style, $at);
    }

    /** @param list<array{node: Node, style: ComputedStyle}> $ancestors the node and its ancestors */
    private function children(Node $node, ComputedStyle $style, Visit $path, TreeVisitor $visitor, Region $region, bool $inHeader, array $ancestors): void
    {
        if ($node instanceof View || $node instanceof Link || $node instanceof TableCell || $node instanceof ListItem || $node instanceof Place) {
            foreach ($node->children() as $i => $child) {
                $this->visit(Expander::expand($child, $this->theme), $style, $path, $i, $visitor, $region, $node, $inHeader, null, $ancestors);
            }
        } elseif ($node instanceof ListNode) {
            foreach ($node->items() as $i => $item) {
                $this->visit($item, $style, $path, $i, $visitor, $region, $node, $inHeader, null, $ancestors);
            }
        } elseif ($node instanceof Table) {
            $headers = count($node->headerRows());
            foreach ([...$node->headerRows(), ...$node->bodyRows()] as $r => $row) {
                $this->visit($row, $style, $path, $r, $visitor, $region, $node, $r < $headers, null, $ancestors);
            }
        } elseif ($node instanceof TableRow) {
            foreach ($node->cells() as $j => $cell) {
                $this->visit($cell, $style, $path, $j, $visitor, $region, $node, $inHeader, $style, $ancestors);
            }
        }
    }
}
