<?php

declare(strict_types=1);

namespace Papercut\Tests\Layout;

use Papercut\{Column, ColumnBreak, ColumnFlow, Document, Image, Link, Page, PageBreak, Row, Table, TableCell, Text, View};
use Papercut\Exception\{InvalidStructure, LayoutOverflow};
use Papercut\Layout\{LayoutContext, LayoutEngine, Paginator};
use Papercut\Paint\{Bookmark, Line, PageDisplayList, Painter, TextRun};
use Papercut\Paint\Link as LinkOp;
use Papercut\Style\Theme;
use Papercut\Tests\Support\FakeBackend;
use Papercut\Text\FontRegistry;
use Papercut\Value\Color;
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

final class ColumnFlowLayoutTest extends TestCase
{
    /** @var list<string> */
    private array $warnings = [];

    /** @return list<PageDisplayList> */
    private function paginate(Document $doc, \Papercut\OverflowMode $mode = \Papercut\OverflowMode::Throw): array
    {
        $b = new FakeBackend();
        $ctx = new LayoutContext(FontRegistry::withDefaults($b), Theme::default(), $b, $mode, function (string $w): void { $this->warnings[] = $w; });
        return Painter::paint((new Paginator(new LayoutEngine($ctx), $ctx))->paginate($doc));
    }

    /** 220x100 page, 10pt margins: a 200x80 body, five 14pt lines per column. */
    private function page(): Page
    {
        return Page::size(pt(220), pt(100))->margin(pt(10))->headerGap(pt(0))->footerGap(pt(0));
    }

    private function flow(\Papercut\Node ...$children): View
    {
        return ColumnFlow::make(...$children)->columnGap(pt(20));
    }

    /** @return list<Text> */
    private static function lines(int $from, int $to): array
    {
        return array_map(fn (int $i) => Text::make("L$i"), range($from, $to));
    }

    /** @return list<array{string, float}> text and x of every run */
    private function runs(PageDisplayList $p): array
    {
        $out = [];
        foreach ($p->ops as $o) {
            if ($o instanceof TextRun) {
                $out[] = [$o->text, round($o->x, 3)];
            }
        }
        return $out;
    }

    /** @return list<array{string, float}> */
    private static function at(string $prefix, int $from, int $to, float $x): array
    {
        return array_map(fn (int $i) => ["$prefix$i", $x], range($from, $to));
    }

    public function test_fills_column_one_then_column_two(): void
    {
        $pages = $this->paginate(Document::make($this->page()->body($this->flow(...self::lines(1, 8)))));
        self::assertCount(1, $pages);
        self::assertSame([...self::at('L', 1, 4, 10.0), ...self::at('L', 5, 8, 120.0)], $this->runs($pages[0]));
    }

    public function test_content_continues_on_the_next_page_in_column_one(): void
    {
        $pages = $this->paginate(Document::make($this->page()->body($this->flow(...self::lines(1, 14)))));
        self::assertCount(2, $pages);
        self::assertSame([...self::at('L', 1, 5, 10.0), ...self::at('L', 6, 10, 120.0)], $this->runs($pages[0]));
        self::assertSame([...self::at('L', 11, 12, 10.0), ...self::at('L', 13, 14, 120.0)], $this->runs($pages[1]));
    }

    public function test_column_break_moves_to_the_next_column(): void
    {
        $pages = $this->paginate(Document::make($this->page()->body($this->flow(...self::lines(1, 2), ...[ColumnBreak::make()], ...self::lines(3, 4)))));
        self::assertCount(1, $pages);
        self::assertSame([...self::at('L', 1, 2, 10.0), ...self::at('L', 3, 4, 120.0)], $this->runs($pages[0]));
    }

    public function test_column_break_in_the_last_column_starts_a_new_page(): void
    {
        $pages = $this->paginate(Document::make($this->page()->body($this->flow(
            Text::make('L1'),
            ColumnBreak::make(),
            Text::make('L2'),
            ColumnBreak::make(),
            Text::make('L3'),
        ))));
        self::assertCount(2, $pages);
        self::assertSame([['L1', 10.0], ['L2', 120.0]], $this->runs($pages[0]));
        self::assertSame([['L3', 10.0]], $this->runs($pages[1]));
    }

    public function test_page_break_in_a_flow_skips_the_remaining_columns(): void
    {
        $pages = $this->paginate(Document::make($this->page()->body($this->flow(Text::make('L1'), PageBreak::make(), Text::make('L2')))));
        self::assertCount(2, $pages);
        self::assertSame([['L1', 10.0]], $this->runs($pages[0]));
        self::assertSame([['L2', 10.0]], $this->runs($pages[1]));
    }

    public function test_keep_with_next_heading_moves_to_the_next_column_with_its_paragraph(): void
    {
        $pages = $this->paginate(Document::make($this->page()->body($this->flow(
            ...self::lines(1, 4),
            ...[Text::make('Head')->keepWithNext(), Text::make('Para')],
        ))));
        self::assertCount(1, $pages);
        self::assertSame([...self::at('L', 1, 3, 10.0), ['L4', 120.0], ['Head', 120.0], ['Para', 120.0]], $this->runs($pages[0]));
    }

    public function test_full_width_text_after_a_flow_starts_below_its_tallest_column(): void
    {
        $flow = $this->flow(...self::lines(1, 3), ...[ColumnBreak::make()], ...self::lines(4, 4));
        $pages = $this->paginate(Document::make($this->page()->body($flow, Text::make('After'))));
        self::assertCount(1, $pages);
        $runs = array_column(array_map(fn (TextRun $r) => [$r->text, $r->baseline], array_values(array_filter($pages[0]->ops, fn ($o) => $o instanceof TextRun))), 1, 0);
        // the first column is three lines (42pt) tall, so the full-width line sits one line below L3
        self::assertEqualsWithDelta($runs['L3'] + 14.0, $runs['After'], 1e-9);
        self::assertSame([['L1', 10.0], ['L2', 10.0], ['L3', 10.0], ['L4', 120.0], ['After', 10.0]], $this->runs($pages[0]));
    }

    public function test_two_flows_on_one_page_with_text_between(): void
    {
        $doc = Document::make(Page::size(pt(220), pt(200))->margin(pt(10))->headerGap(pt(0))->footerGap(pt(0))->body(
            $this->flow(...self::lines(1, 7)),
            Text::make('Mid'),
            $this->flow(...self::lines(8, 9)),
        ));
        $pages = $this->paginate($doc);
        self::assertCount(1, $pages);
        // 180pt body, so the columns hold 12 lines each, but the last page of each flow is balanced: 4 and 3, then 1 and 1.
        self::assertSame([...self::at('L', 1, 4, 10.0), ...self::at('L', 5, 7, 120.0), ['Mid', 10.0], ['L8', 10.0], ['L9', 120.0]], $this->runs($pages[0]));
    }

    public function test_rule_draws_one_line_per_gap_at_the_gap_centre(): void
    {
        $flow = $this->flow(...self::lines(1, 8))->columnRule(pt(1), Color::black());
        $pages = $this->paginate(Document::make($this->page()->body($flow)));
        $lines = array_values(array_filter($pages[0]->ops, fn ($o) => $o instanceof Line));
        self::assertCount(1, $lines);
        self::assertEqualsWithDelta(110.0, $lines[0]->x1, 1e-9);
        self::assertEqualsWithDelta(110.0, $lines[0]->x2, 1e-9);
        self::assertEqualsWithDelta(10.0, $lines[0]->y1, 1e-9);
        self::assertEqualsWithDelta(66.0, $lines[0]->y2, 1e-9);
        self::assertEqualsWithDelta(1.0, $lines[0]->width, 1e-9);
    }

    public function test_overflow_path_names_the_flow(): void
    {
        try {
            $this->paginate(Document::make($this->page()->body(ColumnFlow::make(Image::bytes('x')->width(pt(50))->height(pt(500))))));
            self::fail('expected LayoutOverflow');
        } catch (LayoutOverflow $e) {
            self::assertSame('page > body > column-flow[0] > image[0]', $e->path);
        }
    }

    public function test_flow_inside_a_padded_box_gets_the_box_content_width(): void
    {
        $box = View::make($this->flow(...self::lines(1, 8)))->padding(pt(20));
        $pages = $this->paginate(Document::make(Page::size(pt(300), pt(200))->margin(pt(10))->headerGap(pt(0))->footerGap(pt(0))->body($box)));
        // content 260 - 40 padding = 220 wide: columns of 100 at x 30 and 150; 160pt of height holds 11 lines, but the last page is balanced to 4 and 4
        self::assertSame([...self::at('L', 1, 4, 30.0), ...self::at('L', 5, 8, 160.0)], $this->runs($pages[0]));

        $narrow = View::make($this->flow(...self::lines(1, 14)))->padding(pt(20));
        $pages = $this->paginate(Document::make($this->page()->body($narrow)));
        // 200 - 40 = 160 wide, columns of 70 at x 30 and 120; 40pt of height holds two lines per column
        self::assertSame([['L1', 30.0], ['L2', 30.0], ['L3', 120.0], ['L4', 120.0]], array_slice($this->runs($pages[0]), 0, 4));
    }

    public function test_flow_in_a_table_cell_throws_naming_the_cell(): void
    {
        $this->expectException(InvalidStructure::class);
        $this->expectExceptionMessageMatches('/table cell.*page > body > table\\[0\\] > tr\\[0\\] > td\\[0\\] > column-flow\\[0\\]/s');
        $this->paginate(Document::make($this->page()->body(Table::make()->row(TableCell::make($this->flow(Text::make('a')))))));
    }

    public function test_flow_in_a_row_throws(): void
    {
        $this->expectException(InvalidStructure::class);
        $this->expectExceptionMessageMatches('/inside a row/');
        $this->paginate(Document::make($this->page()->body(Row::make($this->flow(Text::make('a'))))));
    }

    public function test_flow_in_a_flow_throws(): void
    {
        $this->expectException(InvalidStructure::class);
        $this->expectExceptionMessageMatches('/inside another ColumnFlow/');
        $this->paginate(Document::make($this->page()->body(ColumnFlow::make($this->flow(Text::make('a'))))));
    }

    public function test_flow_in_a_header_throws(): void
    {
        $this->expectException(InvalidStructure::class);
        $this->expectExceptionMessageMatches('/header, footer/');
        $this->paginate(Document::make($this->page()->header($this->flow(Text::make('a')))->body('x')));
    }

    public function test_markup_flow_in_a_cell_lays_out_one_column_with_a_note(): void
    {
        $spec = ColumnFlow::make(Text::make('a'), Text::make('b'))->columnSpec();
        self::assertNotNull($spec);
        $flow = ColumnFlow::make(Text::make('a'), Text::make('b'))->withColumnSpec($spec->withFromMarkup());
        $pages = $this->paginate(Document::make($this->page()->body(Table::make()->row(TableCell::make($flow)))));
        self::assertSame([['a', 16.0], ['b', 16.0]], $this->runs($pages[0]));
        self::assertCount(1, $this->warnings);
        self::assertStringContainsString('columns in a table cell', $this->warnings[0]);
    }

    public function test_flow_with_its_own_border_throws(): void
    {
        $this->expectException(InvalidStructure::class);
        $this->expectExceptionMessageMatches('/can\'t have a border, background, padding or size/');
        $this->paginate(Document::make($this->page()->body($this->flow(Text::make('a'))->border(pt(1)))));
    }

    public function test_flow_with_a_background_or_padding_throws(): void
    {
        foreach ([fn (View $v) => $v->padding(pt(4)), fn (View $v) => $v->background('#eee')] as $style) {
            try {
                $this->paginate(Document::make($this->page()->body($style($this->flow(Text::make('a'))))));
                self::fail('expected InvalidStructure');
            } catch (InvalidStructure $e) {
                self::assertStringContainsString('Put it inside a View with that style.', $e->getMessage());
            }
        }
    }

    public function test_headings_in_a_flow_keep_their_bookmarks_and_links_work(): void
    {
        $pages = $this->paginate(Document::make($this->page()->body($this->flow(
            ...self::lines(1, 5),
            ...[Text::h1('Chapter'), Link::to('https://example.com', Text::make('Go'))],
        ))));
        $ops = $pages[0]->ops;
        $bookmarks = array_values(array_filter($ops, fn ($o) => $o instanceof Bookmark));
        self::assertCount(1, $bookmarks);
        self::assertSame('Chapter', $bookmarks[0]->title);
        self::assertGreaterThanOrEqual(120.0, $bookmarks[0]->x);
        $links = array_values(array_filter($ops, fn ($o) => $o instanceof LinkOp));
        self::assertCount(1, $links);
        self::assertEqualsWithDelta(120.0, $links[0]->x, 1e-9);
    }

    public function test_flow_with_its_own_size_throws(): void
    {
        $sizes = [
            fn (View $v) => $v->height(pt(60)),
            fn (View $v) => $v->width(pt(100)),
            fn (View $v) => $v->minHeight(pt(60)),
            fn (View $v) => $v->maxWidth(pt(100)),
        ];
        foreach ($sizes as $size) {
            try {
                $this->paginate(Document::make($this->page()->body($size($this->flow(...self::lines(1, 6))))));
                self::fail('expected InvalidStructure');
            } catch (InvalidStructure $e) {
                self::assertStringContainsString('border, background, padding or size', $e->getMessage());
                self::assertStringContainsString('Put it inside a View with that style.', $e->getMessage());
            }
        }
    }

    public function test_markup_flow_in_a_row_keeps_its_one_column_width(): void
    {
        $base = ColumnFlow::make(Text::make('aa'));
        $spec = $base->columnSpec();
        self::assertNotNull($spec);
        $flow = $base->withColumnSpec($spec->withFromMarkup());
        $pages = $this->paginate(Document::make($this->page()->body(Row::make($flow, Text::make('zz')))));
        $runs = array_column($this->runs($pages[0]), 1, 0);
        self::assertSame(10.0, $runs['aa']);
        // 'aa' is one column wide, not two columns plus a gap: zz sits right after it
        self::assertEqualsWithDelta(10.0 + 10.0, $runs['zz'], 1e-9);
    }

    public function test_gaps_that_leave_no_room_throw_with_the_flow_path(): void
    {
        $doc = Document::make($this->page()->body(ColumnFlow::make(Text::make('abc'))->columns(4)->columnGap(pt(80))));
        try {
            $this->paginate($doc);
            self::fail('Expected LayoutOverflow.');
        } catch (LayoutOverflow $e) {
            self::assertStringEndsWith('column-flow[0]', $e->path);
        }
    }

    public function test_default_gaps_in_a_narrow_box_throw_with_the_flow_path(): void
    {
        $doc = Document::make($this->page()->body(View::make(ColumnFlow::make(Text::make('abc'))->columns(4))->width(pt(100))));
        try {
            $this->paginate($doc);
            self::fail('Expected LayoutOverflow.');
        } catch (LayoutOverflow $e) {
            self::assertStringEndsWith('column-flow[0]', $e->path);
        }
    }

    public function test_gaps_that_leave_no_room_warn_and_stay_in_the_box_when_clipping(): void
    {
        $doc = Document::make($this->page()->body(View::make(ColumnFlow::make(Text::make('abc'))->columns(4))->width(pt(100))));
        $pages = $this->paginate($doc, \Papercut\OverflowMode::Clip);
        self::assertCount(1, $this->warnings);
        self::assertStringContainsString('leave no room for columns', $this->warnings[0]);
        self::assertSame([['abc', 10.0]], $this->runs($pages[0]));
    }
}
