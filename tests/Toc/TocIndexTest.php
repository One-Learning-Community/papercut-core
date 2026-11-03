<?php

declare(strict_types=1);

namespace Papercut\Tests\Toc;

use Papercut\{Column, Document, ListItem, ListNode, Page, Row, Table, TableCell, TableRow, Text, Toc};
use Papercut\Style\{StyleResolver, Theme};
use Papercut\Toc\{TocEntry, TocIndex};
use PHPUnit\Framework\TestCase;

final class TocIndexTest extends TestCase
{
    /** @return list<array{string, int, string}> */
    private static function triples(TocIndex $index, int $from, int $to): array
    {
        return array_map(static fn (TocEntry $e): array => [$e->key, $e->level, $e->text], $index->entries($from, $to));
    }

    private static function collect(Document $doc): TocIndex
    {
        return TocIndex::collect($doc, new StyleResolver(Theme::default()));
    }

    public function test_default_levels_list_h1_and_h2(): void
    {
        $index = self::collect(Document::make(Page::a4()->body(Toc::make(), Text::h1('A'), Text::h2('B'), Text::h3('C'))));
        self::assertTrue($index->hasToc());
        self::assertSame([['page > body > text[1]', 1, 'A'], ['page > body > text[2]', 2, 'B']], self::triples($index, 1, 2));
        self::assertSame('papercut-toc-0', $index->forKey('page > body > text[1]')?->destination);
        self::assertSame('papercut-toc-1', $index->forKey('page > body > text[2]')?->destination);
        self::assertNull($index->forKey('page > body > text[3]'));
    }

    public function test_bookmark_title_and_level_apply(): void
    {
        $doc = Document::make(Page::a4()->body(
            Toc::make()->levels(1, 3),
            Text::h1('Long title')->bookmark('Short'),
            Text::h1('Long title')->bookmark(false),
            Text::h1('X')->bookmark('X', 3),
            Text::h1("  spaced \n  out  "),
        ));
        self::assertSame([
            ['page > body > text[1]', 1, 'Short'],
            ['page > body > text[2]', 1, 'Long title'],
            ['page > body > text[3]', 3, 'X'],
            ['page > body > text[4]', 1, 'spaced out'],
        ], self::triples(self::collect($doc), 1, 3));
    }

    public function test_a_blank_bookmark_title_falls_back_to_the_heading_text(): void
    {
        $doc = Document::make(Page::a4()->body(Toc::make(), Text::h1('Heading')->bookmark('   '), Text::h1('Other')->bookmark('')));
        self::assertSame([
            ['page > body > text[1]', 1, 'Heading'],
            ['page > body > text[2]', 1, 'Other'],
        ], self::triples(self::collect($doc), 1, 3));
    }

    public function test_toc_false_and_empty_headings_are_skipped(): void
    {
        $doc = Document::make(Page::a4()->body(Toc::make(), Text::h2('Hidden')->toc(false), Text::h1('  ')));
        self::assertSame([], self::triples(self::collect($doc), 1, 4));
    }

    public function test_markup_with_toc_off_adds_no_entries(): void
    {
        $html = '<h2>Warm-up</h2><p>Text</p><table><tr><td><h3>In a cell</h3></td></tr></table>';
        $doc = Document::make(Page::a4()->body(
            Toc::make()->levels(1, 4),
            Text::h1('Unit 1'),
            \Papercut\Markup\Markup::html($html, new \Papercut\Markup\MarkupOptions(toc: false)),
            Text::h1('Unit 2'),
            \Papercut\Markup\Markup::html($html),
        ));
        self::assertSame(['Unit 1', 'Unit 2', 'Warm-up', 'In a cell'], array_map(static fn (array $t): string => $t[2], self::triples(self::collect($doc), 1, 4)));
    }

    public function test_only_the_body_counts(): void
    {
        $doc = Document::make(Page::a4()
            ->header(Text::h1('Head'))->footer(Text::h1('Foot'))->background(Text::h1('Back'))
            ->body(Toc::make(), Text::h1('Body')));
        self::assertSame([['page > body > text[1]', 1, 'Body']], self::triples(self::collect($doc), 1, 2));
    }

    public function test_keys_match_layout_paths(): void
    {
        $reused = Text::h2('Reused');
        $doc = Document::make(Page::a4()->body(
            Toc::make(),
            $reused,
            Row::make(Text::make('x'), Column::make(Text::h1('InRow'))),
            Text::make('p'),
            Text::make('p'),
            Text::make('p'),
            Text::make('p'),
            Text::make('p'),
            $reused,
        ));
        $doc2 = Document::make(Page::a4()->body(
            Toc::make(), Text::make('1'), Row::make(Text::make('x'), Column::make(Text::h1('InRow'))),
        ));
        self::assertSame(
            [['page > body > text[1]', 2, 'Reused'], ['page > body > text[8]', 2, 'Reused']],
            array_values(array_filter(self::triples(self::collect($doc), 1, 2), static fn (array $t): bool => $t[2] === 'Reused')),
        );
        self::assertSame([['page > body > row[2] > column[1] > text[0]', 1, 'InRow']], self::triples(self::collect($doc2), 1, 2));
    }

    public function test_keys_inside_tables_and_lists(): void
    {
        $table = Table::make()->bodyRow(TableRow::make(TableCell::make(Text::h2('InCell'))));
        $doc = Document::make(Page::a4()->body(
            Toc::make(), Text::make('a'), Text::make('b'), $table, Text::make('c'), Text::make('d'),
            ListNode::unordered(ListItem::make(Text::h2('InItem'))),
        ));
        self::assertSame([
            ['page > body > table[3] > tr[0] > td[0] > text[0]', 2, 'InCell'],
            ['page > body > list[6] > item[0] > text[0]', 2, 'InItem'],
        ], self::triples(self::collect($doc), 1, 2));
    }

    public function test_pages_are_indexed_in_keys(): void
    {
        $doc = Document::make(Page::a4()->body(Toc::make(), Text::h1('One')), Page::a4()->body(Text::h1('Two')));
        self::assertSame([
            ['page[0] > body > text[1]', 1, 'One'],
            ['page[1] > body > text[0]', 1, 'Two'],
        ], self::triples(self::collect($doc), 1, 4));
    }

    public function test_several_tocs_each_see_their_own_levels(): void
    {
        $doc = Document::make(Page::a4()->body(
            Toc::make(), Toc::make()->levels(3, 3), Text::h1('A'), Text::h2('B'), Text::h3('C'),
        ));
        $index = self::collect($doc);
        self::assertSame([['page > body > text[2]', 1, 'A'], ['page > body > text[3]', 2, 'B']], self::triples($index, 1, 2));
        self::assertSame([['page > body > text[4]', 3, 'C']], self::triples($index, 3, 3));
        foreach ([2, 3, 4] as $i) {
            self::assertNotNull($index->forKey('page > body > text[' . $i . ']'));
        }
    }

    public function test_no_toc_means_no_entries(): void
    {
        $index = self::collect(Document::make(Page::a4()->body(Text::h1('A'))));
        self::assertFalse($index->hasToc());
        self::assertNull($index->forKey('page > body > text[0]'));
        self::assertSame([], $index->entries(1, 4));
        self::assertFalse(TocIndex::empty()->hasToc());
    }
}
