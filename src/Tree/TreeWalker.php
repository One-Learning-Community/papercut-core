<?php

declare(strict_types=1);

namespace Papercut\Tree;

use Papercut\{Component, Document, Link, ListItem, ListNode, Node, Page, Place, Table, TableCell, TableRow, View};
use Papercut\Exception\{InvalidValue, UnknownAttribute};
use Papercut\Style\{ComputedStyle, StyleResolver};

/**
 * Walks a Document's expanded node tree without laying it out: pages, then
 * regions (background, header, body, footer, overlay), then nodes, with computed styles
 * and paths. Paths match layout's in every region, so errors read the same
 * as from toPdf().
 */
final class TreeWalker
{
    public function __construct(private readonly StyleResolver $styles)
    {
    }

    public function walk(Document $doc, TreeVisitor $visitor): void
    {
        $pages = $doc->pages();
        $root = $this->styles->root();
        foreach ($pages as $i => $page) {
            $name = count($pages) > 1 ? sprintf('page[%d]', $i) : 'page';
            $visitor->enterPage($page, $i);
            foreach ($this->regions($page) as [$region, $nodes]) {
                if ($nodes === []) {
                    continue;
                }
                $visitor->enterRegion($region, $root);
                foreach ($nodes as $j => $node) {
                    $this->visit(Expander::expand($node), $root, $name . ' > ' . $region->value, $j, $visitor, $region);
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

    private function visit(Node $node, ComputedStyle $parent, string $parentPath, int $index, TreeVisitor $visitor, Region $region, ?Node $parentNode = null, bool $inHeader = false, ?ComputedStyle $rowStyle = null): void
    {
        $provisional = $parentPath . ' > ' . NodePath::segment($node, null, $index);
        try {
            $style = $node instanceof TableCell && $rowStyle !== null
                ? $this->styles->resolveInTable($node, $rowStyle)
                : $this->styles->resolve($node, $parent);
        } catch (UnknownAttribute | InvalidValue $e) {
            throw $e->within('at ' . $provisional);
        }
        $path = $parentPath . ' > ' . NodePath::segment($node, $style, $index);
        if ($node instanceof Place && ($region !== Region::Overlay || $parentNode !== null)) {
            throw new \LogicException(sprintf('A place only works in a page overlay; add it with Page::overlay(), not inside other content (at %s).', $path));
        }

        $at = new Visit($path, $index, $parentNode, $inHeader, $region);
        if ($visitor->enter($node, $style, $at)) {
            $this->children($node, $style, $path, $visitor, $region, $inHeader);
        }
        $visitor->leave($node, $style, $at);
    }

    private function children(Node $node, ComputedStyle $style, string $path, TreeVisitor $visitor, Region $region, bool $inHeader): void
    {
        if ($node instanceof View || $node instanceof Link || $node instanceof TableCell || $node instanceof ListItem || $node instanceof Place) {
            foreach ($node->children() as $i => $child) {
                $this->visit(Expander::expand($child), $style, $path, $i, $visitor, $region, $node, $inHeader);
            }
        } elseif ($node instanceof ListNode) {
            foreach ($node->items() as $i => $item) {
                $this->visit($item, $style, $path, $i, $visitor, $region, $node, $inHeader);
            }
        } elseif ($node instanceof Table) {
            $headers = count($node->headerRows());
            foreach ([...$node->headerRows(), ...$node->bodyRows()] as $r => $row) {
                $this->visit($row, $style, $path, $r, $visitor, $region, $node, $r < $headers);
            }
        } elseif ($node instanceof TableRow) {
            foreach ($node->cells() as $j => $cell) {
                $this->visit($cell, $style, $path, $j, $visitor, $region, $node, $inHeader, $style);
            }
        }
    }
}
