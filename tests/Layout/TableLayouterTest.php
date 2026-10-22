<?php

declare(strict_types=1);

namespace Papercut\Tests\Layout;

use Papercut\{Component, Node, Table, TableCell, TableRow, Text};
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
}
