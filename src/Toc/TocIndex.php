<?php

declare(strict_types=1);

namespace Papercut\Toc;

use Papercut\{Document, Node, Page, Text, Toc as TocNode};
use Papercut\Style\{ComputedStyle, PageThemes, StyleResolver};
use Papercut\Tree\{Region, TreeVisitor, TreeWalker, Visit};

/**
 * The headings that table-of-contents blocks list, collected from the node tree
 * before layout. Keys are layout paths, so layout can find each heading again.
 *
 * @internal
 */
final class TocIndex implements TreeVisitor
{
    /** @var list<TocEntry> */
    private array $entries = [];
    /** @var array<string, TocEntry> */
    private array $byKey = [];
    /** @var list<array{int, int}> */
    private array $ranges = [];
    /** @var list<array{string, int, string}> */
    private array $candidates = [];

    private function __construct()
    {
    }

    public static function empty(): self
    {
        return new self();
    }

    public static function collect(Document $doc, StyleResolver|PageThemes $styles): self
    {
        $index = new self();
        (new TreeWalker($styles))->walk($doc, $index);
        foreach ($index->candidates as [$key, $level, $text]) {
            foreach ($index->ranges as [$from, $to]) {
                if ($level >= $from && $level <= $to) {
                    $entry = new TocEntry($key, $level, $text, 'papercut-toc-' . count($index->entries));
                    $index->entries[] = $entry;
                    $index->byKey[$key] = $entry;
                    break;
                }
            }
        }
        $index->candidates = [];
        return $index;
    }

    public function hasToc(): bool
    {
        return $this->ranges !== [];
    }

    /** @return list<TocEntry> */
    public function entries(int $from, int $to): array
    {
        return array_values(array_filter($this->entries, static fn (TocEntry $e): bool => $e->level >= $from && $e->level <= $to));
    }

    /** Null when no table of contents in the document lists this heading. */
    public function forKey(string $path): ?TocEntry
    {
        return $this->byKey[$path] ?? null;
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

    public function enter(Node $node, ComputedStyle $style, Visit $at): bool
    {
        if ($at->region() !== Region::Body) {
            return false;
        }
        if ($node instanceof TocNode) {
            $this->ranges[] = [$node->fromLevel(), $node->toLevel()];
            return true;
        }
        if (!$node instanceof Text || $style->toc === false) {
            return true;
        }
        $heading = HeadingText::level($node);
        if ($heading === null) {
            return true;
        }
        $title = $style->bookmark !== null && trim($style->bookmark) !== '' ? $style->bookmark : HeadingText::plain($node);
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));
        if ($title !== '') {
            $this->candidates[] = [$at->path(), $style->bookmarkLevel ?? $heading, $title];
        }
        return true;
    }

    public function leave(Node $node, ComputedStyle $style, Visit $at): void
    {
    }
}
