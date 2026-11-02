<?php

declare(strict_types=1);

namespace Papercut\Tests\Layout;

use Papercut\{Column, ColumnBreak, ColumnFlow, Document, ListNode, PageBreak, Page, Table, TableCell, Text, View};
use Papercut\Exception\InvalidStructure;
use Papercut\Layout\{ForcedBreak, LayoutContext, LayoutEngine, Paginator, Status};
use Papercut\Style\Theme;
use Papercut\Tests\Support\{FakeBackend, LayoutHarness};
use Papercut\Text\FontRegistry;
use PHPUnit\Framework\TestCase;

final class ForcedBreakTest extends TestCase
{
    public function test_page_break_token_is_forced_page(): void
    {
        $r = (new LayoutHarness())->layout(Column::make(Text::make('a'), PageBreak::make(), Text::make('b')), 100);
        self::assertSame(Status::Partial, $r->status);
        self::assertSame(ForcedBreak::Page, $r->token?->forced);
    }

    public function test_break_before_and_after_tokens_are_forced_page(): void
    {
        $h = new LayoutHarness();
        $before = $h->layout(Column::make(Text::make('a'), Text::make('b')->breakBefore()), 100);
        self::assertSame(ForcedBreak::Page, $before->token?->forced);
        $after = $h->layout(Column::make(Text::make('a')->breakAfter(), Text::make('b')), 100);
        self::assertSame(ForcedBreak::Page, $after->token?->forced);
    }

    public function test_space_break_token_is_not_forced(): void
    {
        $r = (new LayoutHarness())->layout(Column::make(Text::make('one'), Text::make('two'), Text::make('three')), 100, 30);
        self::assertSame(Status::Partial, $r->status);
        self::assertNull($r->token?->forced);
    }

    public function test_nested_box_passes_the_forced_kind_up(): void
    {
        $outer = Column::make(Text::make('a'), View::make(View::make(Text::make('b'), PageBreak::make(), Text::make('c'))));
        $r = (new LayoutHarness())->layout($outer, 100);
        self::assertSame(Status::Partial, $r->status);
        self::assertNotNull($r->token?->child);
        self::assertSame(ForcedBreak::Page, $r->token->forced);
    }

    public function test_page_break_inside_a_list_item_in_a_flow_ends_the_page(): void
    {
        $flow = ColumnFlow::make(ListNode::unordered(Column::make(Text::make('a'), PageBreak::make(), Text::make('b'))));
        $r = (new LayoutHarness())->layout($flow, 200);
        self::assertSame(Status::Partial, $r->status);
        self::assertSame(ForcedBreak::Page, $r->token?->forced);
    }

    public function test_column_break_inside_a_flow_moves_to_the_next_column(): void
    {
        $r = (new LayoutHarness())->layout(ColumnFlow::make(Text::make('a'), ColumnBreak::make(), Text::make('b')), 200);
        self::assertSame(Status::Complete, $r->status);
        self::assertCount(2, $r->fragment?->children() ?? []);
    }

    public function test_column_break_in_the_last_column_of_a_flow_is_forced_page(): void
    {
        $r = (new LayoutHarness())->layout(ColumnFlow::make(Text::make('a'), ColumnBreak::make(), Text::make('b'))->columns(1), 200);
        self::assertSame(Status::Partial, $r->status);
        self::assertSame(ForcedBreak::Page, $r->token?->forced);
    }

    public function test_column_break_outside_a_flow_throws_invalid_structure(): void
    {
        $doc = Document::make(Page::size(\Papercut\pt(200), \Papercut\pt(100))->margin(\Papercut\pt(10))->body(Text::make('a'), ColumnBreak::make(), Text::make('b')));
        try {
            $this->paginate($doc);
            self::fail('Expected InvalidStructure');
        } catch (InvalidStructure $e) {
            self::assertSame(
                'A ColumnBreak only works inside a ColumnFlow (at "page > body > column-break[1]").',
                $e->getMessage(),
            );
        }
    }

    public function test_column_break_inside_a_table_cell_inside_a_flow_throws(): void
    {
        $flow = ColumnFlow::make(Table::make()->row(TableCell::make(Column::make(Text::make('a'), ColumnBreak::make(), Text::make('b')))));
        try {
            (new LayoutHarness())->layout($flow, 200);
            self::fail('Expected InvalidStructure');
        } catch (InvalidStructure $e) {
            self::assertSame('A ColumnBreak only works inside a ColumnFlow (at "column-flow > table[0] > tr[0] > td[0] > column[0] > column-break[1]").', $e->getMessage());
        }
    }

    public function test_column_break_directly_in_a_row_throws(): void
    {
        $flow = ColumnFlow::make(\Papercut\Row::make(Text::make('a'), ColumnBreak::make(), Text::make('b')), Text::make('c'));
        try {
            (new LayoutHarness())->layout($flow, 200);
            self::fail('Expected InvalidStructure');
        } catch (InvalidStructure $e) {
            self::assertSame('A ColumnBreak only works inside a ColumnFlow (at "column-flow > row[0] > column-break[1]").', $e->getMessage());
        }
    }

    public function test_column_break_is_skipped_as_a_last_child_and_inside_a_fixed_height_box(): void
    {
        $h = new LayoutHarness();
        $last = $h->layout(ColumnFlow::make(Text::make('a'), ColumnBreak::make()), 200);
        self::assertSame(Status::Complete, $last->status);
        $fixed = $h->layout(ColumnFlow::make(Column::make(Text::make('a'), ColumnBreak::make(), Text::make('b'))->height(\Papercut\pt(100))), 200);
        self::assertSame(Status::Complete, $fixed->status);
        self::assertCount(2, LayoutHarness::runs($fixed->fragment));
    }

    private function paginate(Document $doc): void
    {
        $b = new FakeBackend();
        $ctx = new LayoutContext(FontRegistry::withDefaults($b), Theme::default(), $b, \Papercut\OverflowMode::Throw, static function (string $w): void {
        });
        (new Paginator(new LayoutEngine($ctx), $ctx))->paginate($doc);
    }
}
