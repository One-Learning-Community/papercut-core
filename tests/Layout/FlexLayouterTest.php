<?php

declare(strict_types=1);

namespace Papercut\Tests\Layout;

use Papercut\{Column, Row, Spacer, Text, View};
use Papercut\Layout\{Fragment, Status};
use Papercut\Paint\Rect;
use Papercut\Style\{Align, Justify};
use Papercut\Tests\Support\LayoutHarness;
use Papercut\Value\Color;
use PHPUnit\Framework\TestCase;

use function Papercut\{percent, pt};

final class FlexLayouterTest extends TestCase
{
    /** @return list<float> x of each run */
    private function xs(?Fragment $f): array
    {
        return array_column(LayoutHarness::runs($f), 'x');
    }

    public function test_items_sit_side_by_side_at_content_width(): void
    {
        $f = (new LayoutHarness())->layout(Row::make('aa', 'bbb'), 200)->fragment;
        self::assertSame([0.0, 10.0], $this->xs($f));
        self::assertSame(14.0, $f?->height);
    }

    public function test_gap_and_justify(): void
    {
        $h = new LayoutHarness();
        self::assertSame([0.0, 14.0], $this->xs($h->layout(Row::make('aa', 'bbb')->gap(pt(4)), 200)->fragment));
        self::assertSame([0.0, 185.0], $this->xs($h->layout(Row::make('aa', 'bbb')->justify(Justify::SpaceBetween), 200)->fragment));
        self::assertSame([175.0, 185.0], $this->xs($h->layout(Row::make('aa', 'bbb')->justify(Justify::End), 200)->fragment));
        self::assertSame([87.5, 97.5], $this->xs($h->layout(Row::make('aa', 'bbb')->justify(Justify::Center), 200)->fragment));
    }

    public function test_spacer_flex_pushes_right(): void
    {
        self::assertSame([0.0, 185.0], $this->xs((new LayoutHarness())->layout(Row::make('aa', Spacer::flex(), 'bbb'), 200)->fragment));
    }

    public function test_grow_distributes_proportionally(): void
    {
        // CSS semantics: basis auto = max-content (5pt each); free 190 split 1:3 => a = 5 + 47.5
        $row = Row::make(View::make('a')->grow(1), View::make('b')->grow(3));
        self::assertSame([0.0, 52.5], $this->xs((new LayoutHarness())->layout($row, 200)->fragment));
        // basis 0 gives exact 1:3 shares
        $row0 = Row::make(View::make('a')->grow(1)->basis(pt(0)), View::make('b')->grow(3)->basis(pt(0)));
        self::assertSame([0.0, 50.0], $this->xs((new LayoutHarness())->layout($row0, 200)->fragment));
    }

    public function test_percentage_widths(): void
    {
        $row = Row::make(View::make('a')->width(percent(25)), View::make('b')->width(percent(75)));
        self::assertSame([0.0, 50.0], $this->xs((new LayoutHarness())->layout($row, 200)->fragment));
    }

    public function test_shrink_wraps_text_in_narrow_row(): void
    {
        $row = Row::make(View::make('aaaa bbbb'), View::make('cccc dddd'));
        $f = (new LayoutHarness())->layout($row, 60)->fragment;
        self::assertSame(28.0, $f?->height); // each item shrinks to 30 and wraps to 2 lines
    }

    public function test_align_items_center_and_stretch_backgrounds(): void
    {
        $tall = View::make('a', 'b');
        $short = View::make('c')->background(Color::white());
        $f = (new LayoutHarness())->layout(Row::make($tall, $short), 100)->fragment;
        $bg = null;
        foreach ($f?->children() ?? [] as $c) {
            foreach ($c['fragment']->ops() as $op) {
                if ($op instanceof Rect) { $bg = $op; }
            }
        }
        self::assertSame(28.0, $bg?->height); // stretched to line height
        $runs = LayoutHarness::runs((new LayoutHarness())->layout(Row::make($tall, View::make('c'))->align(Align::Center), 100)->fragment);
        self::assertSame(17.0, $runs[2]['baseline']); // (28 - 14) / 2 + 10
    }

    public function test_wrap_breaks_into_lines(): void
    {
        $row = Row::make(View::make('aaaa')->width(pt(60)), View::make('bbbb')->width(pt(60)))->wrap()->gap(pt(10));
        $f = (new LayoutHarness())->layout($row, 100)->fragment;
        self::assertSame(38.0, $f?->height); // 14 + gap 10 + 14
    }

    public function test_row_moves_as_unit_when_not_fresh(): void
    {
        self::assertSame(Status::DoesNotFit, (new LayoutHarness())->layout(Row::make(View::make('a', 'b')), 100, 20, fresh: false)->status);
    }

    public function test_parallel_split_when_fresh(): void
    {
        $row = Row::make(Column::make('a', 'b', 'c')->minLines(1), Column::make('x')->minLines(1));
        $h = new LayoutHarness();
        $r = $h->layout($row, 100, 30, fresh: true);
        self::assertSame(Status::Partial, $r->status);
        self::assertNotNull($r->token?->parallel[0] ?? null);
        self::assertNotNull($r->token);
        self::assertTrue(array_key_exists(1, $r->token->parallel));
        self::assertNull($r->token->parallel[1]);
        $rest = $h->layout($row, 100, null, $r->token);
        self::assertSame(['c'], array_column(LayoutHarness::runs($rest->fragment), 'text'));
    }

    public function test_intrinsic(): void
    {
        $s = (new LayoutHarness())->engine->intrinsic(Row::make('aa', 'bbbb')->gap(pt(5)), \Papercut\Style\ComputedStyle::root(\Papercut\Style\Theme::default()));
        self::assertSame(35.0, $s['max']);
    }

    public function test_items_take_their_resolved_main_size(): void
    {
        // the percentage resolves against the row (50pt), not again against the item's slot
        $row = Row::make(View::make('a')->width(percent(25)), View::make('bb cc')->width(pt(60)));
        $f = (new LayoutHarness())->layout($row, 200)->fragment;
        self::assertSame([50.0, 60.0], array_map(fn (array $c): float => $c['fragment']->width, $f?->children() ?? []));
        // an explicit width still shrinks to fit: 50 + 60 in 80 => shrink ∝ base: 50 - 30×50/110, 60 - 30×60/110
        $narrow = (new LayoutHarness())->layout(Row::make(View::make('a')->width(pt(50)), View::make('b')->width(pt(60))), 80)->fragment;
        $widths = array_map(fn (array $c): float => $c['fragment']->width, $narrow?->children() ?? []);
        self::assertEqualsWithDelta(80.0, array_sum($widths), 1e-9);
        self::assertEqualsWithDelta(50 - 30 * 50 / 110, $widths[0], 1e-9);
    }

    public function test_parallel_resume_draws_only_unfinished_items(): void
    {
        $row = Row::make(Column::make('a', 'b', 'c')->minLines(1), Column::make('x', 'y')->minLines(1));
        $h = new LayoutHarness();
        $r = $h->layout($row, 100, 30, fresh: true);
        self::assertSame(['a', 'b', 'x', 'y'], array_column(LayoutHarness::runs($r->fragment), 'text'));
        self::assertSame(Status::Partial, $r->status);
        $rest = $h->layout($row, 100, 30, $r->token);
        self::assertSame(Status::Complete, $rest->status);
        self::assertSame(['c'], array_column(LayoutHarness::runs($rest->fragment), 'text'));
        self::assertSame(14.0, $rest->fragment?->height);
    }

    public function test_stretched_row_fills_but_stays_splittable_while_explicit_height_is_monolithic(): void
    {
        $h = new LayoutHarness();
        $row = Row::make(Column::make('a', 'b', 'c')->minLines(1))->background(Color::white());
        $filled = $h->engine->layout($row, \Papercut\Style\ComputedStyle::root(\Papercut\Style\Theme::default()), new \Papercut\Layout\Constraints(100, null, true, 60.0));
        self::assertSame(60.0, $filled->fragment?->height);
        $split = $h->engine->layout($row, \Papercut\Style\ComputedStyle::root(\Papercut\Style\Theme::default()), new \Papercut\Layout\Constraints(100, 30, true, 60.0));
        self::assertSame(Status::Partial, $split->status);
        self::assertSame(['a', 'b'], array_column(LayoutHarness::runs($split->fragment), 'text'));
        self::assertSame(Status::DoesNotFit, $h->layout($row->height(pt(60)), 100, 30)->status);
    }

    // --- Task 13 ---------------------------------------------------------

    public function test_split_row_truncates_trailing_margin_at_the_available_height(): void
    {
        $row = Row::make(View::make('a')->margin(pt(0), pt(0), pt(20), pt(0)));
        $r = (new LayoutHarness())->layout($row, 200, 20);
        self::assertSame(Status::Complete, $r->status);
        self::assertNotNull($r->fragment);
        self::assertLessThanOrEqual(20.0, $r->fragment->height);
    }

    // --- Final review ---

    public function test_page_break_inside_a_row_is_ignored_with_a_warning(): void
    {
        $h = new LayoutHarness();
        $r = $h->layout(Row::make(Text::make('a'), \Papercut\PageBreak::make(), Text::make('b')), 100, 100);
        self::assertSame(Status::Complete, $r->status);
        self::assertSame(['a', 'b'], array_column(LayoutHarness::runs($r->fragment), 'text'));
        self::assertSame(['[row] PageBreak inside a row is ignored'], $h->warnings);
    }
}
