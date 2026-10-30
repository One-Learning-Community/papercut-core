<?php

declare(strict_types=1);

namespace Papercut\Tests\Layout;

use Papercut\{Component, Node, OverflowMode, Table, TableCell, TableRow, Text};
use Papercut\Exception\LayoutOverflow;
use Papercut\Layout\{Fragment, Status};
use Papercut\Paint\{Line, Rect};
use Papercut\Style\{ComputedStyle, Theme};
use Papercut\Value\Edges;
use Papercut\Tests\Support\LayoutHarness;
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

final class TableLayouterTest extends TestCase
{
    // cell padding 4pt 6pt => cell height 14 + 8 = 22
    public function test_column_widths_fixed_fr_auto(): void
    {
        $t = Table::make()->columns('100pt 1fr auto')->row('a', 'b', 'cc');
        $runs = LayoutHarness::runs((new LayoutHarness())->layout($t, 400)->fragment);
        // auto col = 10 + 12 padding = 22 => fr col = 400 - 100 - 22 = 278
        self::assertSame([6.0, 106.0, 384.0], array_column($runs, 'x'));
    }

    public function test_fill_width_shares_leftover_among_auto_columns_by_max_content(): void
    {
        $t = Table::make()->columns('auto auto')->row('aa', 'aaaa');
        $h = new LayoutHarness();
        $plain = LayoutHarness::runs($h->layout($t, 400)->fragment);
        $filled = LayoutHarness::runs($h->layout($t->fillWidth(), 400)->fragment);
        // 5pt per character: max-content 10 + 12 and 20 + 12
        self::assertSame(22.0 + 6.0, $plain[1]['x']);
        self::assertEqualsWithDelta(400.0 * 22 / 54 + 6.0, $filled[1]['x'], 1e-6);
    }

    public function test_fill_width_does_nothing_when_an_fr_track_takes_the_rest(): void
    {
        $t = Table::make()->columns('auto 1fr')->row('aa', 'b');
        $h = new LayoutHarness();
        $plain = LayoutHarness::runs($h->layout($t, 400)->fragment);
        $filled = LayoutHarness::runs($h->layout($t->fillWidth(), 400)->fragment);
        self::assertSame($plain, $filled);
    }

    public function test_fill_width_without_auto_columns_changes_nothing(): void
    {
        $t = Table::make()->columns('100pt 100pt')->row('a', 'b');
        $h = new LayoutHarness();
        self::assertSame(
            LayoutHarness::runs($h->layout($t, 400)->fragment),
            LayoutHarness::runs($h->layout($t->fillWidth(), 400)->fragment),
        );
    }

    public function test_hand_built_over_wide_length_tracks_throw_in_throw_mode(): void
    {
        $t = Table::make()->columns('300pt 300pt')->row('a', 'b');
        $this->expectException(LayoutOverflow::class);
        $this->expectExceptionMessage('Table columns need 600.00pt but only 400.00pt is available');
        (new LayoutHarness())->layout($t, 400);
    }

    public function test_hand_built_over_wide_length_tracks_overflow_and_warn_in_clip_mode(): void
    {
        $t = Table::make()->columns('300pt 300pt')->row('a', 'b');
        $h = new LayoutHarness(overflow: OverflowMode::Clip);
        self::assertSame([300.0, 300.0], $this->columnWidths($t, 400.0, OverflowMode::Clip));
        $h->layout($t, 400);
        self::assertCount(1, $h->warnings);
        self::assertStringContainsString('Table columns need', $h->warnings[0]);
    }

    // 5pt per character, 12pt cell padding: 'aa' needs 22pt, twenty a's 112pt
    public function test_auto_columns_narrower_than_their_content_shrink_the_widest_first(): void
    {
        $t = Table::make()->columns('auto auto')->row('aa', str_repeat('a', 20));
        self::assertEqualsWithDelta([22.0, 78.0], $this->columnWidths($t, 100.0), 1e-6);

        $h = new LayoutHarness();
        $f = $h->layout($t, 100)->fragment;
        self::assertNotNull($f);
        self::assertLessThanOrEqual(100.0 + 1e-6, self::rightEdge($f));
        self::assertCount(1, $h->warnings);
        self::assertStringContainsString('pdf: approximated table columns narrower than their content', $h->warnings[0]);
        self::assertStringContainsString('34.00pt more', $h->warnings[0]);
        self::assertStringContainsString('smaller text or less cell padding', $h->warnings[0]);
    }

    public function test_an_fr_column_narrower_than_its_content_shrinks_too(): void
    {
        $t = Table::make()->columns('auto 1fr')->row('aaaa', str_repeat('b', 30));
        self::assertEqualsWithDelta([32.0, 68.0], $this->columnWidths($t, 100.0), 1e-6);
    }

    public function test_fitted_length_columns_shrink_below_their_content_when_the_minimums_do_not_fit(): void
    {
        $t = Table::make()->columns('100pt 100pt')->fitColumns()->row(str_repeat('a', 30), str_repeat('a', 30));
        self::assertEqualsWithDelta([50.0, 50.0], $this->columnWidths($t, 100.0), 1e-6);
    }

    // floor per column: 12pt padding + its widest letter (5pt)
    public function test_columns_never_shrink_below_their_padding_and_widest_letter(): void
    {
        $t = Table::make()->columns('auto auto')->row('aaaa', 'aaaa');
        self::assertEqualsWithDelta([17.0, 17.0], $this->columnWidths($t, 30.0, OverflowMode::Clip), 1e-6);
    }

    public function test_a_table_that_cannot_shrink_enough_throws_in_throw_mode(): void
    {
        $t = Table::make()->columns('auto auto')->row('aaaa', 'aaaa');
        $this->expectException(LayoutOverflow::class);
        (new LayoutHarness())->layout($t, 30);
    }

    public function test_a_table_that_cannot_shrink_enough_overflows_and_warns_in_clip_mode(): void
    {
        $t = Table::make()->columns('auto auto')->row('aaaa', 'aaaa');
        $h = new LayoutHarness(overflow: OverflowMode::Clip);
        $h->layout($t, 30);
        self::assertStringContainsString('Table columns need 34.00pt but only 30.00pt is available', implode("\n", $h->warnings));
    }

    // the inner table can shrink to 17 + 17, plus the outer cell's 12pt padding: 46
    public function test_a_nested_table_keeps_the_width_its_own_columns_can_shrink_to(): void
    {
        $inner = Table::make()->columns('auto auto')->row('aaaa', 'aaaa');
        $t = Table::make()->columns('auto auto')->row(TableCell::make($inner), str_repeat('c', 20));
        self::assertEqualsWithDelta([46.0, 34.0], $this->columnWidths($t, 80.0), 1e-6);
        $h = new LayoutHarness();
        $f = $h->layout($t, 80)->fragment;
        self::assertNotNull($f);
        self::assertLessThanOrEqual(80.0 + 1e-6, self::rightEdge($f));
    }

    public function test_a_fixed_width_box_keeps_its_column_wide_enough(): void
    {
        $box = \Papercut\Column::make(Text::make('x'))->width(pt(100));
        $t = Table::make()->columns('auto auto')->row(TableCell::make($box), str_repeat('c', 40));
        self::assertEqualsWithDelta([112.0, 38.0], $this->columnWidths($t, 150.0), 1e-6);
    }

    public function test_a_column_covered_only_by_spanning_cells_keeps_their_floor(): void
    {
        $t = Table::make()->columns('auto auto')->row(TableCell::make('aaaa')->colspan(2));
        self::assertEqualsWithDelta([8.5, 8.5], $this->columnWidths($t, 6.0, OverflowMode::Clip), 1e-6);
        $this->expectException(LayoutOverflow::class);
        (new LayoutHarness())->layout($t, 6);
    }

    public function test_an_image_column_shrinks_to_its_padding(): void
    {
        $t = Table::make()->columns('auto auto')->row(TableCell::make(\Papercut\Image::bytes(\Papercut\Tests\Support\TestImages::png(200, 10))), str_repeat('c', 40));
        $widths = $this->columnWidths($t, 100.0);
        self::assertEqualsWithDelta(100.0, array_sum($widths), 1e-6);
        self::assertEqualsWithDelta($widths[0], $widths[1], 1e-6);
    }

    public function test_a_nested_table_with_fitted_length_columns_keeps_what_they_can_shrink_to(): void
    {
        $inner = Table::make()->columns('100pt 100pt')->fitColumns()->row('aaaa', 'aaaa');
        $t = Table::make()->columns('auto auto')->row(TableCell::make($inner), str_repeat('c', 40));
        self::assertEqualsWithDelta([46.0, 34.0], $this->columnWidths($t, 80.0), 1e-6);
        (new LayoutHarness())->layout($t, 80);
        $this->addToAssertionCount(1); // did not throw
    }

    public function test_svgs_and_images_with_a_width_scale_down_with_their_column(): void
    {
        $svg = \Papercut\Svg::fromString('<svg xmlns="http://www.w3.org/2000/svg" width="400" height="40" viewBox="0 0 400 40"><rect width="400" height="40"/></svg>');
        $image = \Papercut\Image::bytes(\Papercut\Tests\Support\TestImages::png(40, 10))->width(pt(300));
        foreach ([$svg, $image] as $box) {
            $t = Table::make()->columns('auto auto')->row(TableCell::make($box), 'text');
            $f = (new LayoutHarness())->layout($t, 200)->fragment;
            self::assertNotNull($f);
            self::assertLessThanOrEqual(200.0 + 1e-6, self::rightEdge($f));
        }
    }

    public function test_fr_columns_covered_only_by_a_spanning_cell_share_its_floor(): void
    {
        $t = Table::make()->columns('1fr 1fr 1fr')->row(TableCell::make('aaaa')->colspan(2), str_repeat('a', 40));
        self::assertEqualsWithDelta([8.5, 8.5, 83.0], $this->columnWidths($t, 100.0), 1e-6);
    }

    // all three were given 100pt, so the shared cap shrinks them alike; none drops to 0
    public function test_fitted_length_columns_covered_only_by_a_spanning_cell_do_not_drop_to_nothing(): void
    {
        $t = Table::make()->columns('100pt 100pt 100pt')->fitColumns()->row(TableCell::make('aaaa')->colspan(2), str_repeat('a', 40));
        self::assertEqualsWithDelta([100 / 3, 100 / 3, 100 / 3], $this->columnWidths($t, 100.0), 1e-6);
    }

    // a letter is 5pt plus 10pt letter spacing: wider than one em
    public function test_the_text_floor_is_its_widest_letter_with_letter_spacing(): void
    {
        $cell = static fn (): TableCell => TableCell::make(Text::make('aaaa')->letterSpacing(pt(10)));
        $t = Table::make()->columns('auto auto')->row($cell(), $cell());
        self::assertEqualsWithDelta([27.0, 27.0], $this->columnWidths($t, 50.0, OverflowMode::Clip), 1e-6);
    }

    private static function rightEdge(Fragment $f, float $ox = 0.0): float
    {
        $right = $ox + $f->width;
        foreach ($f->children() as $c) {
            $right = max($right, self::rightEdge($c['fragment'], $ox + $c['x']));
        }
        return $right;
    }

    public function test_fit_columns_scales_length_tracks_down_to_the_width_without_a_warning(): void
    {
        $t = Table::make()->columns('300pt 300pt')->fitColumns()->row('a', 'b');
        $h = new LayoutHarness();
        $widths = $this->columnWidths($t, 400.0);
        self::assertEqualsWithDelta([200.0, 200.0], $widths, 1e-6);
        $h->layout($t, 400);
        self::assertSame([], $h->warnings);
    }

    public function test_fit_columns_leaves_room_for_the_other_columns_minimum(): void
    {
        $t = Table::make()->columns('300pt 100pt auto')->fitColumns()->row('a', 'b', 'cccc');
        $widths = $this->columnWidths($t, 300.0);
        // auto min-content: 4 characters x 5pt + 12pt padding = 32
        self::assertEqualsWithDelta(300.0, array_sum($widths), 1e-6);
        self::assertEqualsWithDelta(32.0, $widths[2], 1e-6);
        self::assertEqualsWithDelta(3.0, $widths[0] / $widths[1], 1e-6);
    }

    public function test_fit_columns_does_not_squeeze_a_length_column_below_its_min_content(): void
    {
        $t = Table::make()->columns('300pt 300pt')->fitColumns()->row('aaaaaaaaaaaaaaaaaaaa', 'b');
        $widths = $this->columnWidths($t, 200.0);
        // the first column's longest word is 20 x 5 + 12 = 112pt
        self::assertEqualsWithDelta(112.0, $widths[0], 1e-6);
        self::assertEqualsWithDelta(88.0, $widths[1], 1e-6);
    }

    public function test_fit_columns_leaves_tracks_that_already_fit_alone(): void
    {
        $t = Table::make()->columns('100pt 100pt')->fitColumns()->row('a', 'b');
        self::assertSame([100.0, 100.0], $this->columnWidths($t, 400.0));
    }

    /** @return list<float> */
    private function columnWidths(Table $t, float $width, OverflowMode $overflow = OverflowMode::Throw): array
    {
        $engine = (new LayoutHarness(overflow: $overflow))->engine;
        return (new \Papercut\Layout\TableLayouter(new \Papercut\Layout\BlockLayouter()))->columns($t, ComputedStyle::root(Theme::default()), $width, $engine);
    }

    public function test_colspan(): void
    {
        $t = Table::make()->columns('1fr 1fr')->row(TableCell::make('wide')->colspan(2)->right());
        $runs = LayoutHarness::runs((new LayoutHarness())->layout($t, 200)->fragment);
        self::assertSame(174.0, $runs[0]['x']); // 200 - 6 - 20
    }

    public function test_header_repeats_on_continuation(): void
    {
        $t = Table::make()->columns('1fr')->head('H')->rows(range(1, 5), fn (int $i) => ["r$i"]);
        $h = new LayoutHarness();
        $first = $h->layout($t, 100, 66); // header 22 + 2 rows
        self::assertSame(Status::Partial, $first->status);
        self::assertSame(['H', 'r1', 'r2'], array_column(LayoutHarness::runs($first->fragment), 'text'));
        $second = $h->layout($t, 100, null, $first->token);
        self::assertSame(['H', 'r3', 'r4', 'r5'], array_column(LayoutHarness::runs($second->fragment), 'text'));
        $noRepeat = $h->layout($t->repeatHeader(false), 100, null, $first->token);
        self::assertSame(['r3', 'r4', 'r5'], array_column(LayoutHarness::runs($noRepeat->fragment), 'text'));
    }

    public function test_th_is_bold_by_default(): void
    {
        $f = (new LayoutHarness())->layout(Table::make()->head('H')->row('b'), 100)->fragment;
        self::assertNotNull($f);
        $fonts = [];
        $walk = function (\Papercut\Layout\Fragment $fr) use (&$walk, &$fonts): void {
            foreach ($fr->ops() as $op) {
                if ($op instanceof \Papercut\Paint\TextRun) { $fonts[$op->text] = $op->font->id(); }
            }
            foreach ($fr->children() as $c) { $walk($c['fragment']); }
        };
        $walk($f);
        self::assertSame('fake:Inter-Bold.ttf', $fonts['H']);
        self::assertSame('fake:Inter-Regular.ttf', $fonts['b']);
    }

    public function test_track_mismatch_warns_and_defaults_to_fr(): void
    {
        $h = new LayoutHarness();
        $r = $h->layout(Table::make()->columns('1fr 1fr')->row('a', 'b', 'c'), 300);
        self::assertSame(Status::Complete, $r->status);
        self::assertCount(1, $h->warnings);
        self::assertSame([6.0, 106.0, 206.0], array_column(LayoutHarness::runs($r->fragment), 'x'));
    }

    public function test_not_fresh_first_row_does_not_fit(): void
    {
        $t = Table::make()->columns('1fr')->row('a');
        self::assertSame(Status::DoesNotFit, (new LayoutHarness())->layout($t, 100, 10, fresh: false)->status);
    }

    public function test_extra_tracks_are_ignored_with_a_warning(): void
    {
        $h = new LayoutHarness();
        $r = $h->layout(Table::make()->columns('50pt 50pt 50pt')->row('a', 'b'), 300);
        self::assertSame(['[table] Table has 2 columns but 3 tracks'], $h->warnings);
        self::assertSame([6.0, 56.0], array_column(LayoutHarness::runs($r->fragment), 'x'));
    }

    public function test_percent_track_resolves_against_table_content_width(): void
    {
        $t = Table::make()->columns('25% 1fr')->padding(\Papercut\pt(10))->row('a', 'b');
        $runs = LayoutHarness::runs((new LayoutHarness())->layout($t, 220)->fragment);
        // content width 200 => 50 + 150
        self::assertSame([16.0, 66.0], array_column($runs, 'x'));
    }

    public function test_auto_tracks_shrink_toward_min_content_when_max_content_overflows(): void
    {
        // max-content 25+12 = 37 each (74 > 60); min-content 10+12 = 22 each; 16 spare split by (max-min)
        $t = Table::make()->columns('auto auto')->row('aa bb', 'cc dd');
        $runs = LayoutHarness::runs((new LayoutHarness())->layout($t, 60)->fragment);
        self::assertSame([6.0, 36.0], array_values(array_unique(array_column($runs, 'x'))));
    }

    public function test_cells_are_stretched_to_the_row_height(): void
    {
        $t = Table::make()->columns('1fr 1fr')->row(TableCell::make('a')->background('#ff0000'), 'b c');
        $f = (new LayoutHarness())->layout($t, 40)->fragment; // col content 8 => "b c" wraps to 2 lines: 28 + 8
        self::assertNotNull($f);
        self::assertSame(36.0, $f->height);
        $rects = array_values(array_filter(self::cellFragments($f)[0]->ops(), fn ($op) => $op instanceof Rect));
        self::assertSame(36.0, $rects[0]->height);
    }

    public function test_oversized_row_splits_its_cells_in_parallel_and_repeats_the_header(): void
    {
        $t = Table::make()->columns('1fr 1fr')->head('H', 'I')->row(TableCell::make('a', 'b', 'c'), 'x')->row('y', 'z');
        $h = new LayoutHarness();
        $first = $h->layout($t, 100, 60); // header 22, row needs 50 > 38 left
        self::assertSame(Status::Partial, $first->status);
        self::assertSame(['H', 'I', 'a', 'b', 'x'], array_column(LayoutHarness::runs($first->fragment), 'text'));
        self::assertNotNull($first->token);
        self::assertSame(0, $first->token->index);
        self::assertNotNull($first->token->parallel[0] ?? null);
        self::assertNull($first->token->parallel[1]);

        $rest = $h->layout($t, 100, null, $first->token);
        self::assertSame(Status::Complete, $rest->status);
        self::assertSame(['H', 'I', 'c', 'y', 'z'], array_column(LayoutHarness::runs($rest->fragment), 'text'));
        self::assertSame(22.0 + 18.0 + 22.0, $rest->fragment?->height);
    }

    public function test_keep_together_moves_the_whole_table_unless_fresh(): void
    {
        $t = Table::make()->columns('1fr')->keepTogether()->rows(range(1, 5), fn (int $i) => ["r$i"]);
        $h = new LayoutHarness();
        self::assertSame(Status::DoesNotFit, $h->layout($t, 100, 66, fresh: false)->status);
        self::assertSame(Status::Partial, $h->layout($t, 100, 66)->status);
    }

    public function test_intrinsic_sums_columns(): void
    {
        $t = Table::make()->columns('50pt auto')->row('a', 'bbbb');
        $s = (new LayoutHarness())->engine->intrinsic($t, ComputedStyle::root(Theme::default()));
        self::assertSame(['min' => 82.0, 'max' => 82.0], $s);
    }

    public function test_row_background_spans_the_table_width(): void
    {
        $t = Table::make()->columns('1fr 1fr')->row('x')->bodyRow(TableRow::make('a', 'b')->background('slate-100'));
        $f = (new LayoutHarness())->layout($t, 200)->fragment;
        self::assertNotNull($f);
        $row = $f->children()[1];
        self::assertSame([22.0, 200.0, 22.0], [$row['y'], $row['fragment']->width, $row['fragment']->height]);
        $rects = array_values(array_filter($row['fragment']->ops(), fn ($op) => $op instanceof Rect));
        self::assertCount(1, $rects);
        self::assertSame([0.0, 0.0, 200.0, 22.0], [$rects[0]->x, $rects[0]->y, $rects[0]->width, $rects[0]->height]);
    }

    public function test_row_border_draws_a_bottom_line(): void
    {
        $t = Table::make()->columns('1fr')->bodyRow(TableRow::make('a')->borderWidth(Edges::of(pt(0), pt(0), pt(0.5), pt(0))));
        $f = (new LayoutHarness())->layout($t, 100)->fragment;
        self::assertSame(22.5, $f?->height);
        $row = $f->children()[0]['fragment'];
        $lines = array_values(array_filter($row->ops(), fn ($op) => $op instanceof Line));
        self::assertCount(1, $lines);
        self::assertSame([0.0, 22.25, 100.0, 22.25], [$lines[0]->x1, $lines[0]->y1, $lines[0]->x2, $lines[0]->y2]);
    }

    public function test_row_padding_offsets_cells(): void
    {
        $h = new LayoutHarness();
        $plain = LayoutHarness::runs($h->layout(Table::make()->columns('1fr')->row('a'), 100)->fragment)[0];
        $t = Table::make()->columns('1fr')->bodyRow(TableRow::make('a')->padding(pt(3)));
        $r = $h->layout($t, 100);
        self::assertSame(28.0, $r->fragment?->height);
        self::assertSame(9.0, LayoutHarness::runs($r->fragment)[0]['x']);
        self::assertSame($plain['baseline'] + 3.0, LayoutHarness::runs($r->fragment)[0]['baseline']);
    }

    public function test_warnings_fire_once_when_the_table_is_laid_out_again(): void
    {
        $h = new LayoutHarness();
        $t = Table::make()->columns('1fr')->row('a', 'b');
        self::assertSame(Status::DoesNotFit, $h->layout($t, 100, 10, fresh: false)->status);
        self::assertSame(Status::Complete, $h->layout($t, 100)->status);
        self::assertSame(['[table] Table has 2 columns but 1 tracks'], $h->warnings);
    }

    public function test_column_measures_are_cached_per_table(): void
    {
        $counter = new class () implements Component {
            public int $renders = 0;

            public function render(): Node|Component
            {
                $this->renders++;
                return Text::make('c');
            }
        };
        $t = Table::make()->columns('auto 1fr')->row(TableCell::make($counter), 'x');
        $h = new LayoutHarness();
        $root = ComputedStyle::root(Theme::default());
        $h->engine->intrinsic($t, $root);
        $before = $counter->renders;
        $h->engine->intrinsic($t, $root);
        self::assertSame($before, $counter->renders);

        $fresh = new LayoutHarness(); // a new engine has nothing cached yet
        $before = $counter->renders;
        $fresh->layout($t, 200);
        $firstLayout = $counter->renders - $before;
        $fresh->layout($t, 200);
        $secondLayout = $counter->renders - $before - $firstLayout;
        // the grid for width 200 is reused: only the cell layout itself renders again
        self::assertLessThan($firstLayout, $secondLayout);
        $fresh->layout($t, 200);
        self::assertSame($secondLayout, $counter->renders - $before - $firstLayout - $secondLayout);
    }

    public function test_spanning_cells_size_the_auto_columns_they_cover(): void
    {
        // span-1: 5+12 = 17 each; the spanning cell's max 45+12 = 57 adds (57-34)/2 to each => 28.5
        $t = Table::make()->columns('auto auto')->row(TableCell::make('aaaa bbbb')->colspan(2))->row('x', 'y');
        $runs = LayoutHarness::runs((new LayoutHarness())->layout($t, 200)->fragment);
        self::assertSame(34.5, array_column($runs, 'x', 'text')['y']);

        $only = Table::make()->columns('auto auto')->row(TableCell::make('aaaa bbbb')->colspan(2));
        $s = (new LayoutHarness())->engine->intrinsic($only, ComputedStyle::root(Theme::default()));
        self::assertSame(['min' => 32.0, 'max' => 57.0], $s);
    }

    public function test_fr_columns_never_shrink_below_min_content(): void
    {
        // 1fr of 100 = 25 < min-content 20+12 = 32: frozen at 32, the 3fr column takes the remaining 68
        $h = new LayoutHarness();
        $t = Table::make()->columns('1fr 3fr')->row('aaaa', 'b');
        $f = $h->layout($t, 100)->fragment;
        $runs = LayoutHarness::runs($f);
        self::assertSame([6.0, 38.0], array_column($runs, 'x'));
        self::assertNotNull($f);
        self::assertSame([32.0, 68.0], array_map(fn (Fragment $c) => $c->width, self::cellFragments($f)));
        self::assertSame([], $h->warnings);
    }

    public function test_header_that_does_not_fit_a_non_fresh_fragment_does_not_fit(): void
    {
        $t = Table::make()->columns('1fr')->head('H');
        self::assertSame(Status::DoesNotFit, (new LayoutHarness())->layout($t, 100, 10, fresh: false)->status);
    }

    public function test_repeated_header_is_dropped_when_it_leaves_no_room_for_a_row(): void
    {
        $t = Table::make()->columns('1fr')->head(TableCell::th('H1', 'H2', 'H3'))->rows(range(1, 6), fn (int $i) => ["r$i"]);
        $h = new LayoutHarness();
        $first = $h->layout($t, 100, 100); // header 50 + 2 rows
        self::assertSame(['H1', 'H2', 'H3', 'r1', 'r2'], array_column(LayoutHarness::runs($first->fragment), 'text'));
        $second = $h->layout($t, 100, 60, $first->token); // 50 + 22 > 60
        self::assertSame(Status::Partial, $second->status);
        self::assertSame(['r3', 'r4'], array_column(LayoutHarness::runs($second->fragment), 'text'));
        $third = $h->layout($t, 100, 60, $second->token);
        self::assertSame(['r5', 'r6'], array_column(LayoutHarness::runs($third->fragment), 'text'));
        self::assertCount(1, $h->warnings);
        $roomy = $h->layout($t, 100, 100, $first->token);
        self::assertSame(['H1', 'H2', 'H3', 'r3', 'r4'], array_column(LayoutHarness::runs($roomy->fragment), 'text'));
    }

    // --- Task 13 ---------------------------------------------------------

    public function test_row_side_edges_keep_cells_inside_the_row_box(): void
    {
        $t = Table::make()->columns('1fr 1fr')->row('x', 'y')->bodyRow(TableRow::make('a', 'b')->padding(pt(10)));
        $f = (new LayoutHarness())->layout($t, 200)->fragment;
        self::assertNotNull($f);
        foreach ($f->children() as $row) {
            self::assertSame(200.0, $row['fragment']->width);
            foreach ($row['fragment']->children() as $cell) {
                self::assertLessThanOrEqual(190.0 + 1e-9, $cell['x'] + $cell['fragment']->width);
            }
        }
        $padded = $f->children()[1]['fragment']->children();
        self::assertSame([10.0, 100.0], array_column($padded, 'x'));
    }

    public function test_body_row_adds_a_table_row_and_row_rejects_one(): void
    {
        $t = Table::make()->bodyRow(TableRow::make('a', 'b')->background('slate-100'));
        self::assertCount(1, $t->bodyRows());
        self::assertCount(2, $t->bodyRows()[0]->cells());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Use Table::bodyRow() to add a TableRow.');
        Table::make()->row(TableRow::make('a'));
    }

    public function test_column_measures_are_shared_across_styles_that_do_not_change_text_metrics(): void
    {
        $counter = new class () implements Component {
            public int $renders = 0;

            public function render(): Node|Component
            {
                $this->renders++;
                return Text::make('c');
            }
        };
        $t = Table::make()->columns('auto')->row(TableCell::make($counter));
        $h = new LayoutHarness();
        $root = ComputedStyle::root(Theme::default());
        $h->engine->intrinsic($t, $root);
        $before = $counter->renders;
        $h->engine->intrinsic($t, $root->child(\Papercut\Style\Style::make(color: \Papercut\Value\Color::parse('red-500'), background: \Papercut\Value\Color::white())));
        self::assertSame($before, $counter->renders);
        $h->engine->intrinsic($t, $root->child(\Papercut\Style\Style::make(fontSize: pt(20))));
        self::assertGreaterThan($before, $counter->renders);
    }

    private static function counter(): Component
    {
        return new class () implements Component {
            public int $renders = 0;

            public function render(): Node|Component
            {
                $this->renders++;
                return Text::make('c');
            }
        };
    }

    public function test_cells_in_fixed_width_tracks_are_not_measured(): void
    {
        $fixed = self::counter();
        $fr = self::counter();
        $t = Table::make()->columns('50pt 1fr')->row(TableCell::make($fixed), TableCell::make($fr));
        $size = (new LayoutHarness())->engine->intrinsic($t, ComputedStyle::root(Theme::default()));
        self::assertSame(0, $fixed->renders); // @phpstan-ignore property.notFound
        self::assertGreaterThan(0, $fr->renders); // @phpstan-ignore property.notFound
        self::assertSame(50.0 + 5.0 + 12.0, $size['min']); // 50pt track + "c" (5) + cell padding (12)
    }

    public function test_short_undecorated_cells_are_laid_out_once_and_keep_the_row_height(): void
    {
        $short = self::counter();
        $t = Table::make()->columns('50pt 20pt')->row(TableCell::make(\Papercut\View::make($short)), 'b c'); // "b c" wraps to 2 lines: 28 + 8
        $f = (new LayoutHarness())->layout($t, 70)->fragment;
        self::assertNotNull($f);
        self::assertSame(1, $short->renders); // @phpstan-ignore property.notFound
        self::assertSame(36.0, $f->height);
        self::assertSame([36.0, 36.0], array_map(static fn (Fragment $c): float => $c->height, self::cellFragments($f)));
    }

    public function test_bottom_aligned_cells_are_stretched_before_aligning(): void
    {
        $t = Table::make()->columns('50pt 20pt')->row(TableCell::make('a')->apply(\Papercut\Style\Style::make(justify: \Papercut\Style\Justify::End)), 'b c');
        $runs = LayoutHarness::runs((new LayoutHarness())->layout($t, 70)->fragment);
        $top = LayoutHarness::runs((new LayoutHarness())->layout(Table::make()->columns('50pt 20pt')->row('a', 'b c'), 70)->fragment);
        self::assertSame('a', $runs[0]['text']);
        self::assertEqualsWithDelta($top[0]['baseline'] + 14.0, $runs[0]['baseline'], 1e-9); // one line lower
    }

    public function test_growing_cell_children_are_stretched(): void
    {
        $t = Table::make()->columns('50pt 20pt')->row(TableCell::make(\Papercut\View::make('a')->grow()->background('#ff0000')), 'b c');
        $f = (new LayoutHarness())->layout($t, 70)->fragment;
        self::assertNotNull($f);
        $grown = self::cellFragments($f)[0]->children()[0]['fragment'];
        self::assertSame(28.0, $grown->height); // row content 36 - cell padding 8
    }

    // --- rowspan ----------------------------------------------------------

    public function test_rowspan_cell_spans_its_rows_and_later_cells_shift_right(): void
    {
        $t = Table::make()->columns('1fr 1fr')->row(TableCell::make('a')->rowspan(2), 'b')->row('c');
        $f = (new LayoutHarness())->layout($t, 200)->fragment;
        $runs = array_column(LayoutHarness::runs($f), null, 'text');
        self::assertSame([6.0, 106.0, 106.0], [$runs['a']['x'], $runs['b']['x'], $runs['c']['x']]);
        self::assertSame($runs['b']['baseline'] + 22.0, $runs['c']['baseline']);
        self::assertSame($runs['b']['baseline'], $runs['a']['baseline']);
        self::assertSame(44.0, $f?->height);
    }

    public function test_spanning_cell_shortfall_is_spread_evenly_over_its_rows(): void
    {
        $t = Table::make()->columns('1fr 1fr')->row(TableCell::make('1', '2', '3')->rowspan(2), 'b')->row('c');
        $f = (new LayoutHarness())->layout($t, 200)->fragment;
        self::assertSame(50.0, $f?->height);
        $runs = array_column(LayoutHarness::runs($f), null, 'text');
        self::assertSame($runs['b']['baseline'] + 25.0, $runs['c']['baseline']);
    }

    public function test_later_row_background_does_not_cover_the_spanning_cell(): void
    {
        $t = Table::make()->columns('1fr 1fr')->row(TableCell::make('a')->rowspan(2), 'b')
            ->bodyRow(TableRow::make('c')->background('slate-100'));
        $f = (new LayoutHarness())->layout($t, 200)->fragment;
        self::assertNotNull($f);
        $ops = [];
        $walk = function (Fragment $fr, float $oy) use (&$walk, &$ops): void {
            foreach ($fr->ops() as $op) {
                $ops[] = [$op, $oy];
            }
            foreach ($fr->children() as $c) {
                $walk($c['fragment'], $oy + $c['y']);
            }
        };
        $walk($f, 0.0);
        $text = null;
        $rect = null;
        foreach ($ops as $i => [$op, $oy]) {
            if ($op instanceof \Papercut\Paint\TextRun && $op->text === 'a') {
                $text = $i;
            }
            if ($op instanceof Rect && $oy + $op->y === 22.0) {
                $rect = $i;
            }
        }
        self::assertNotNull($text);
        self::assertNotNull($rect);
        self::assertGreaterThan($rect, $text);
    }

    public function test_rowspan_cell_counts_toward_its_column_content(): void
    {
        $t = Table::make()->columns('auto 1fr')->row(TableCell::make('wide-word')->rowspan(2), 'b')->row('c');
        $runs = array_column(LayoutHarness::runs((new LayoutHarness())->layout($t, 200)->fragment), null, 'text');
        $column = 45.0 + 12.0; // 'wide-word' at 5pt a glyph + cell padding
        self::assertSame([$column + 6.0, $column + 6.0], [$runs['b']['x'], $runs['c']['x']]);
    }

    // --- rowspan page breaks -----------------------------------------------

    public function test_group_that_does_not_fit_moves_to_the_next_page(): void
    {
        $t = Table::make()->columns('1fr 1fr')->row('x')->row(TableCell::make('a')->rowspan(2), 'b')->row('c');
        $h = new LayoutHarness();
        $first = $h->layout($t, 200, 50); // room for 'x' + one row
        self::assertSame(Status::Partial, $first->status);
        self::assertSame(['x'], self::texts($first->fragment));
        $rest = $h->layout($t, 200, null, $first->token);
        self::assertSame(Status::Complete, $rest->status);
        self::assertEqualsCanonicalizing(['a', 'b', 'c'], self::texts($rest->fragment));
    }

    public function test_group_taller_than_a_page_splits_row_by_row(): void
    {
        $t = Table::make()->columns('1fr 1fr')->row(TableCell::make('a')->rowspan(4), 'r1')->row('r2')->row('r3')->row('r4');
        $pages = self::pages($t, 200, 50);
        self::assertCount(2, $pages);
        self::assertEqualsCanonicalizing(['a', 'r1', 'r2'], $pages[0]);
        self::assertSame(['r3', 'r4'], $pages[1]);
    }

    public function test_tall_spanning_cell_continues_on_the_next_page_once(): void
    {
        $t = Table::make()->columns('1fr 1fr')->row(self::lines(10)->rowspan(2), 'b')->row('c');
        $pages = self::pages($t, 200, 80);
        self::assertGreaterThan(1, count($pages));
        $all = array_merge(...$pages);
        self::assertSame(array_map(static fn (int $i): string => "L$i", range(1, 10)), array_values(array_filter($all, static fn (string $s): bool => $s[0] === 'L')));
        self::assertEqualsCanonicalizing(['b', 'c'], array_values(array_filter($all, static fn (string $s): bool => $s[0] !== 'L')));
    }

    public function test_repeated_header_opens_a_group_continuation(): void
    {
        $t = Table::make()->columns('1fr 1fr')->head('H')->row(self::lines(10)->rowspan(2), 'b')->row('c');
        $pages = self::pages($t, 200, 80);
        self::assertGreaterThan(1, count($pages));
        foreach ($pages as $page) {
            self::assertSame('H', $page[0]);
        }
        $all = array_merge(...$pages);
        self::assertSame(array_map(static fn (int $i): string => "L$i", range(1, 10)), array_values(array_filter($all, static fn (string $s): bool => $s[0] === 'L')));
    }

    public function test_header_only_table_lays_out_once(): void
    {
        $r = (new LayoutHarness())->layout(Table::make()->head('H1')->head('H2'), 100, 100);
        self::assertSame(Status::Complete, $r->status);
        self::assertSame(['H1', 'H2'], self::texts($r->fragment));
    }

    public function test_spanning_cell_taller_than_several_pages_splits_with_its_rows(): void
    {
        $t = Table::make()->columns('1fr 1fr')->row(self::lines(20)->rowspan(2), 'b')->row('c')->row('d');
        $pages = self::pages($t, 200, 80);
        self::assertGreaterThan(3, count($pages));
        $all = array_merge(...$pages);
        self::assertSame(array_map(static fn (int $i): string => "L$i", range(1, 20)), array_values(array_filter($all, static fn (string $s): bool => $s[0] === 'L')));
        self::assertEqualsCanonicalizing(['b', 'c', 'd'], array_values(array_filter($all, static fn (string $s): bool => $s[0] !== 'L')));
        self::assertSame('d', end($all));
    }

    public function test_row_too_tall_inside_a_group_splits_in_parallel_with_the_spanning_cell(): void
    {
        $t = Table::make()->columns('1fr 1fr')->row(TableCell::make('a')->rowspan(2), self::lines(8))->row('c');
        $pages = self::pages($t, 200, 80);
        self::assertCount(2, $pages);
        self::assertContains('a', $pages[0]);
        $all = array_merge(...$pages);
        self::assertSame(array_map(static fn (int $i): string => "L$i", range(1, 8)), array_values(array_filter($all, static fn (string $s): bool => $s[0] === 'L')));
        self::assertSame(1, count(array_keys($all, 'a', true)));
        self::assertSame('c', end($all));
    }

    public function test_header_row_with_a_rowspan_repeats_whole(): void
    {
        $t = Table::make()->columns('1fr 1fr')->head(TableCell::th('H')->rowspan(2), 'I')->head('J')
            ->rows(range(1, 4), fn (int $i) => ["r$i", "s$i"]);
        $pages = self::pages($t, 200, 100); // header 44 + 2 rows
        self::assertCount(2, $pages);
        self::assertEqualsCanonicalizing(['H', 'I', 'J', 'r1', 's1', 'r2', 's2'], $pages[0]);
        self::assertEqualsCanonicalizing(['H', 'I', 'J', 'r3', 's3', 'r4', 's4'], $pages[1]);
    }

    public function test_chained_spans_split_across_a_page_break(): void
    {
        // A spans rows 0-1 in column 0; B spans rows 1-2 in column 1; 'c' sits in column 0 of row 2.
        $t = Table::make()->columns('1fr 1fr 1fr')
            ->row(TableCell::make('A')->rowspan(2), 'b', 'x')
            ->row(TableCell::make('B1', 'B2')->rowspan(2), 'y')
            ->row('c', 'z');
        $pages = self::pages($t, 300, 50); // rows 22 each: two fit, B gets row 1's 22pt for its first line
        self::assertCount(2, $pages);
        self::assertEqualsCanonicalizing(['A', 'b', 'x', 'y', 'B1'], $pages[0]);
        self::assertEqualsCanonicalizing(['B2', 'c', 'z'], $pages[1]);
    }

    public function test_spanning_cells_break_beside_the_last_row_that_fits_without_their_shortfall(): void
    {
        // B spans rows 1-2; row 1 is empty, so row 2 ('c') goes on page 1 too, B breaking beside it.
        $t = Table::make()->columns('1fr 1fr')
            ->row(TableCell::make('A')->rowspan(2), 'b')
            ->row(TableCell::make('B1', 'B2')->rowspan(2))
            ->row('c');
        $pages = self::pages($t, 200, 50);
        self::assertCount(2, $pages);
        self::assertEqualsCanonicalizing(['A', 'b', 'B1', 'c'], $pages[0]);
        self::assertSame(['B2'], $pages[1]);
    }

    public function test_spanning_cell_with_no_room_on_the_page_starts_on_the_next(): void
    {
        // A ties rows 0-1 into B's group. B (an unbreakable 48pt) starts on row 1, which gets only 28pt of
        // the page: B places nothing there and starts whole on page 2.
        $t = Table::make()->columns('1fr 1fr 1fr')
            ->row(TableCell::make('A')->rowspan(2), 'x', 'y')
            ->row(TableCell::make(\Papercut\View::make('B')->height(pt(40)))->rowspan(2), 'c')
            ->row('d', 'e');
        $pages = self::pages($t, 300, 50);
        self::assertCount(2, $pages);
        self::assertEqualsCanonicalizing(['A', 'x', 'y', 'c'], $pages[0]);
        self::assertEqualsCanonicalizing(['B', 'd', 'e'], $pages[1]);
    }

    public function test_resumed_spanning_cell_stretched_to_its_rows_draws_only_what_is_left(): void
    {
        // 'a' (background: stretched by layout) gets L1 on page 1; on page 2 its rest (74) is shorter than row 'M' (78).
        $t = Table::make()->columns('1fr 1fr')
            ->row(self::lines(6)->background('#ff0000')->rowspan(2), 'b')
            ->row(TableCell::make('M1', 'M2', 'M3', 'M4', 'M5'));
        $pages = self::pages($t, 200, 80);
        self::assertCount(2, $pages);
        $all = array_merge(...$pages);
        self::assertSame(array_map(static fn (int $i): string => "L$i", range(1, 6)), array_values(array_filter($all, static fn (string $s): bool => $s[0] === 'L')));
    }

    public function test_rows_beside_a_breaking_spanning_cell_share_its_page(): void
    {
        // 'a' (568pt) breaks anyway: r1..r4 (88pt naturally) all go on page 1 beside its first page-high chunk.
        $t = Table::make()->columns('1fr 1fr')->row(self::lines(40)->rowspan(4), 'r1')->row('r2')->row('r3')->row('r4');
        $pages = self::pages($t, 200, 200);
        self::assertSame(['r1', 'r2', 'r3', 'r4'], array_values(array_filter($pages[0], static fn (string $s): bool => $s[0] === 'r')));
        self::assertCount(3, $pages);
        $all = array_merge(...$pages);
        self::assertSame(array_map(static fn (int $i): string => "L$i", range(1, 40)), array_values(array_filter($all, static fn (string $s): bool => $s[0] === 'L')));
        self::assertSame(4, count(array_filter($all, static fn (string $s): bool => $s[0] === 'r')));
    }

    /** @return iterable<string, array{float, float}> */
    public static function unbreakableSpanningCases(): iterable
    {
        foreach ([[60.0, 70.0], [60.0, 80.0], [70.0, 80.0]] as [$view, $page]) {
            yield "view $view page $page" => [$view, $page];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unbreakableSpanningCases')]
    public function test_unbreakable_spanning_cell_ending_on_the_last_placed_row_is_not_lost(float $viewHeight, float $page): void
    {
        // The group's rows fit the page without B's shortfall, so the last row goes in as a tail; B cannot
        // break there and must start again on the next page with that row, never vanish.
        $t = Table::make()->columns('1fr 1fr 1fr')
            ->row(TableCell::make('A')->rowspan(2), 'x', 'y')
            ->row(TableCell::make(\Papercut\View::make('B')->height(pt($viewHeight)))->rowspan(2), 'c')
            ->row('d', 'e');
        $all = array_merge(...self::pages($t, 300, $page));
        sort($all);
        self::assertSame(['A', 'B', 'c', 'd', 'e', 'x', 'y'], $all);
    }

    public function test_unbreakable_spanning_cell_taller_than_a_fresh_page_behaves_like_a_single_row_cell(): void
    {
        // An unbreakable cell taller than a page is DoesNotFit on a fresh page (the paginator then clips with
        // a warning or throws), as in a single row. The table must not place its other rows and lose the cell.
        $tall = fn () => \Papercut\View::make('B')->height(pt(70));
        $spanning = Table::make()->columns('1fr 1fr')->row('h')->row(TableCell::make($tall())->rowspan(2), 'c')->row('d');
        $single = Table::make()->columns('1fr 1fr')->row('h')->row(TableCell::make($tall()), 'c')->row('d');
        $run = function (Table $t): array {
            $h = new LayoutHarness();
            $r = $h->layout($t, 200, 60, null);
            $first = self::texts($r->fragment);
            $second = $h->layout($t, 200, 60, $r->token);
            return [$first, $second->status, self::texts($second->fragment)];
        };
        self::assertSame($run($single), $run($spanning));
        self::assertSame(['h'], $run($spanning)[0]);
        self::assertSame(Status::DoesNotFit, $run($spanning)[1]);
        self::assertSame([], $run($spanning)[2]);
    }

    public function test_clamped_rowspan_warns_once_per_table(): void
    {
        $t = Table::make()->columns('1fr 1fr')->row(TableCell::make('a')->rowspan(5), 'b')->row('c');
        $h = new LayoutHarness();
        $h->layout($t, 200);
        $h->layout($t, 200, 30);
        self::assertSame(['[table] Table cell rowspan runs past the last row; clamped'], $h->warnings);
    }

    public function test_clamped_colspan_warns_once_per_table(): void
    {
        $t = Table::make()->row('a', 'b', TableCell::make('c')->rowspan(2))->row(TableCell::make('d')->colspan(3));
        $h = new LayoutHarness();
        $h->layout($t, 200);
        $h->layout($t, 200);
        self::assertSame(['[table] Table cell colspan runs into a rowspan from above; clamped'], $h->warnings);
    }

    public function test_unclamped_spans_do_not_warn(): void
    {
        $t = Table::make()->row(TableCell::make('a')->rowspan(2), 'b')->row('c');
        $h = new LayoutHarness();
        $h->layout($t, 200);
        self::assertSame([], $h->warnings);
    }

    // --- every slot paints its whole slice ----------------------------------

    public function test_cells_that_finished_earlier_keep_painting_their_slot_on_the_continuation(): void
    {
        // S ends on page 1 but its rows go on; G (45 lines) spans the same two rows and breaks.
        $t = Table::make()->columns('40pt 100pt 40pt')
            ->row(TableCell::make('S')->rowspan(2)->background('#ff0000'), self::lines(30)->rowspan(2)->background('#00ff00'), TableCell::make('o1')->background('#0000ff'))
            ->row(TableCell::make('o2')->background('#0000ff'));
        $slices = self::slices($t, 180, 150);
        self::assertGreaterThan(1, count($slices));
        foreach ($slices as ['height' => $height, 'fills' => $fills, 'last' => $last]) {
            foreach (['#ff0000', '#00ff00'] as $colour) {
                self::assertArrayHasKey($colour, $fills, 'slot must paint on every slice');
                self::assertSame([[0.0, $height]], self::merged($fills[$colour]), "$colour covers the slice");
            }
        }
    }

    public function test_cell_edges_are_open_on_slices_that_do_not_end_the_cell(): void
    {
        $border = static fn (TableCell $c): TableCell => $c->border(pt(1), '#000000');
        $t = Table::make()->columns('40pt 100pt 40pt')
            ->row($border(TableCell::make('S')->rowspan(2)), $border(self::lines(30)->rowspan(2)), $border(TableCell::make('o1')))
            ->row($border(TableCell::make('o2')));
        $slices = self::slices($t, 180, 150);
        foreach ($slices as $i => ['height' => $height, 'horizontal' => $horizontal, 'last' => $last]) {
            // S (x 0..40) and G (x 40..140): a top edge on the first slice, a bottom edge on the last, none at a cut.
            $own = array_values(array_filter($horizontal, static fn (array $l): bool => $l['x1'] < 139.0));
            $ys = array_values(array_unique(array_map(static fn (array $l): float => round($l['y'], 6), $own)));
            $ys = array_map(static fn (float $y): float => round($y, 6), $ys);
            sort($ys);
            $expected = array_merge($i === 0 ? [0.5] : [], $last ? [$height - 0.5] : []);
            self::assertSame($expected, $ys, "slice $i");
        }
    }

    public function test_cells_beside_a_row_that_splits_fill_every_slice(): void
    {
        $border = static fn (TableCell $c): TableCell => $c->border(pt(1), '#000000');
        $t = Table::make()->columns('50pt 100pt 50pt')
            ->row($border(TableCell::make('Short')->background('#ff0000')), $border(self::lines(30)), $border(TableCell::make('Short')->background('#0000ff')));
        $slices = self::slices($t, 200, 150);
        self::assertGreaterThan(1, count($slices));
        foreach ($slices as ['height' => $height, 'fills' => $fills, 'horizontal' => $horizontal, 'last' => $last]) {
            foreach (['#ff0000', '#0000ff'] as $colour) {
                self::assertArrayHasKey($colour, $fills);
                self::assertSame([[0.0, $height]], self::merged($fills[$colour]));
            }
            if (!$last) {
                self::assertSame([], array_values(array_filter($horizontal, static fn (array $l): bool => abs($l['y'] - ($height - 0.5)) < 1e-6)), 'no bottom edge at the cut');
            }
        }
    }

    /**
     * Rect fills and horizontal border lines (absolute, table origin) of every slice of $t.
     *
     * @return list<array{height: float, last: bool, fills: array<string, list<array{float, float}>>, horizontal: list<array{x1: float, y: float}>}>
     */
    private static function slices(Table $t, float $width, float $height): array
    {
        $h = new LayoutHarness();
        $out = [];
        $token = null;
        for ($i = 0; $i < 30; $i++) {
            $r = $h->layout($t, $width, $height, $token);
            self::assertNotSame(Status::DoesNotFit, $r->status);
            self::assertNotNull($r->fragment);
            $fills = [];
            $horizontal = [];
            $walk = function (Fragment $fr, float $ox, float $oy) use (&$walk, &$fills, &$horizontal): void {
                foreach ($fr->ops() as $op) {
                    if ($op instanceof Rect && $op->fill !== null && $op->stroke === null) {
                        $fills[(string) $op->fill][] = [$oy + $op->y, $oy + $op->y + $op->height];
                    }
                    if ($op instanceof Rect && $op->stroke !== null) { // a closed box: top and bottom edges
                        $horizontal[] = ['x1' => $ox + $op->x, 'y' => $oy + $op->y];
                        $horizontal[] = ['x1' => $ox + $op->x, 'y' => $oy + $op->y + $op->height];
                    }
                    if ($op instanceof Line && abs($op->y1 - $op->y2) < 1e-9) {
                        $horizontal[] = ['x1' => $ox + min($op->x1, $op->x2), 'y' => $oy + $op->y1];
                    }
                }
                foreach ($fr->children() as $c) {
                    $walk($c['fragment'], $ox + $c['x'], $oy + $c['y']);
                }
            };
            $walk($r->fragment, 0.0, 0.0);
            $out[] = ['height' => $r->fragment->height, 'last' => $r->status === Status::Complete, 'fills' => $fills, 'horizontal' => $horizontal];
            if ($r->status === Status::Complete) {
                return $out;
            }
            $token = $r->token;
        }
        self::fail('table never completed');
    }

    /**
     * @param list<array{float, float}> $spans
     * @return list<array{float, float}> overlapping or touching spans merged
     */
    private static function merged(array $spans): array
    {
        usort($spans, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $out = [];
        foreach ($spans as [$a, $b]) {
            if ($out !== [] && $a <= $out[count($out) - 1][1] + 1e-6) {
                $out[count($out) - 1][1] = max($out[count($out) - 1][1], $b);
            } else {
                $out[] = [$a, $b];
            }
        }
        return array_map(static fn (array $s): array => [round($s[0], 6), round($s[1], 6)], $out);
    }

    private static function lines(int $n): TableCell
    {
        return TableCell::make(...array_map(static fn (int $i): string => "L$i", range(1, $n)));
    }

    /** @return list<string> */
    private static function texts(?Fragment $f): array
    {
        return array_column(LayoutHarness::runs($f), 'text');
    }

    /**
     * Lays $t out on fresh pages of $height until complete.
     *
     * @return list<list<string>> texts per page
     */
    private static function pages(Table $t, float $width, float $height): array
    {
        $h = new LayoutHarness();
        $pages = [];
        $token = null;
        for ($i = 0; $i < 30; $i++) {
            $r = $h->layout($t, $width, $height, $token);
            self::assertNotSame(Status::DoesNotFit, $r->status);
            self::assertLessThanOrEqual($height + 1e-9, $r->fragment->height ?? 0.0);
            $pages[] = self::texts($r->fragment);
            if ($r->status === Status::Complete) {
                return $pages;
            }
            $token = $r->token;
        }
        self::fail('table never completed');
    }

    /** @return list<Fragment> cell fragments in paint order */
    private static function cellFragments(Fragment $table): array
    {
        $cells = [];
        foreach ($table->children() as $row) {
            foreach ($row['fragment']->children() as $cell) {
                $cells[] = $cell['fragment'];
            }
        }
        return $cells;
    }

    public function test_bare_cell_warns_once_and_bytes_are_unchanged(): void
    {
        $doc = fn () => \Papercut\Document::make(\Papercut\Page::a4()->body(\Papercut\View::make(TableCell::th('x'))));
        $warnings = [];
        $withHook = $doc()->toPdf(new \Papercut\Export\PdfOptions(
            backend: new \Papercut\Tests\Support\FakeBackend(),
            onWarning: function (string $m) use (&$warnings): void { $warnings[] = $m; },
        ))->bytes();
        $plain = $doc()->toPdf(new \Papercut\Export\PdfOptions(backend: new \Papercut\Tests\Support\FakeBackend()))->bytes();

        $stray = array_values(array_filter($warnings, static fn (string $m): bool => str_ends_with($m, 'A table cell outside a table is laid out as a column')));
        self::assertCount(1, $stray);
        self::assertStringStartsWith('[', $stray[0]);
        self::assertStringContainsString('page > body', $stray[0]);
        self::assertSame($plain, $withHook);
    }

    public function test_reused_cell_warns_when_used_bare_after_a_table(): void
    {
        $cell = TableCell::make('x')->padding(pt(3)); // own padding: theme defaults change nothing
        $doc = \Papercut\Document::make(\Papercut\Page::a4()->body(
            Table::make()->columns('1fr')->row($cell),
            \Papercut\View::make($cell),
        ));
        $warnings = [];
        $doc->toPdf(new \Papercut\Export\PdfOptions(
            backend: new \Papercut\Tests\Support\FakeBackend(),
            onWarning: function (string $m) use (&$warnings): void { $warnings[] = $m; },
        ));

        $stray = array_values(array_filter($warnings, static fn (string $m): bool => str_ends_with($m, 'A table cell outside a table is laid out as a column')));
        self::assertCount(1, $stray);
        self::assertStringContainsString('body > column[1] > td[0]]', $stray[0]);
    }

    /**
     * @return array<string, array{\Papercut\Style\Style}>
     */
    public static function cellWidthConstraints(): array
    {
        return [
            'max-width' => [new \Papercut\Style\Style(maxWidth: \Papercut\px(1))],
            'min-width' => [new \Papercut\Style\Style(minWidth: \Papercut\px(5000))],
            'width' => [new \Papercut\Style\Style(width: \Papercut\px(1))],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cellWidthConstraints')]
    public function test_min_and_max_width_on_a_cell_never_make_it_a_stray_cell(\Papercut\Style\Style $style): void
    {
        $doc = \Papercut\Document::make(\Papercut\Page::a4()->body(
            Table::make()->columns('100pt 1fr')->row(TableCell::make('x')->apply($style), 'y'),
        ));
        $warnings = [];
        $doc->toPdf(new \Papercut\Export\PdfOptions(
            backend: new \Papercut\Tests\Support\FakeBackend(),
            onWarning: function (string $m) use (&$warnings): void { $warnings[] = $m; },
        ));

        self::assertSame([], $warnings);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cellWidthConstraints')]
    public function test_a_cell_width_constraint_leaves_the_columns_where_the_track_list_puts_them(\Papercut\Style\Style $style): void
    {
        $t = Table::make()->columns('100pt 1fr')->row(TableCell::make('x')->apply($style), 'y');
        $runs = LayoutHarness::runs((new LayoutHarness())->layout($t, 400)->fragment);
        self::assertSame([6.0, 106.0], array_column($runs, 'x'));
    }
}
