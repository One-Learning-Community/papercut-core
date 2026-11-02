<?php

declare(strict_types=1);

namespace Papercut\Tests\Layout;

use Papercut\{ColumnBreak, ColumnFlow, Document, Page, PageBreak, Text, View};
use Papercut\Layout\{LayoutContext, LayoutEngine, Paginator};
use Papercut\Paint\{Line, PageDisplayList, Painter, TextRun};
use Papercut\Style\Theme;
use Papercut\Tests\Support\FakeBackend;
use Papercut\Text\FontRegistry;
use Papercut\Value\Color;
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

final class ColumnFlowBalanceTest extends TestCase
{
    /** @var list<string> */
    private array $warnings = [];
    private ?LayoutContext $lastCtx = null;

    /** @return list<PageDisplayList> */
    private function paginate(Document $doc, \Papercut\OverflowMode $mode = \Papercut\OverflowMode::Throw): array
    {
        $b = new FakeBackend();
        $ctx = new LayoutContext(FontRegistry::withDefaults($b), Theme::default(), $b, $mode, function (string $w): void {
            $this->warnings[] = $w;
        });
        $this->lastCtx = $ctx;
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

    /** @return array<string, float> x of each line by text */
    private function xs(PageDisplayList $p): array
    {
        $out = [];
        foreach ($p->ops as $o) {
            if ($o instanceof TextRun) {
                $out[$o->text] = round($o->x, 3);
            }
        }
        return $out;
    }

    /** @return list<string> */
    private function column(PageDisplayList $p, float $x): array
    {
        return array_keys(array_filter($this->xs($p), fn (float $v) => $v === $x));
    }

    /** @return list<Line> */
    private function rules(PageDisplayList $p): array
    {
        return array_values(array_filter($p->ops, fn ($o) => $o instanceof Line));
    }

    public function test_last_page_columns_end_level(): void
    {
        $pages = $this->paginate(Document::make($this->page()->body($this->flow(...self::lines(1, 6)))));
        self::assertCount(1, $pages);
        self::assertSame(['L1', 'L2', 'L3'], $this->column($pages[0], 10.0));
        self::assertSame(['L4', 'L5', 'L6'], $this->column($pages[0], 120.0));
    }

    public function test_odd_count_puts_the_extra_line_in_column_one(): void
    {
        $pages = $this->paginate(Document::make($this->page()->body($this->flow(...self::lines(1, 7)))));
        self::assertSame(['L1', 'L2', 'L3', 'L4'], $this->column($pages[0], 10.0));
        self::assertSame(['L5', 'L6', 'L7'], $this->column($pages[0], 120.0));
    }

    public function test_full_pages_are_not_balanced(): void
    {
        $pages = $this->paginate(Document::make($this->page()->body($this->flow(...self::lines(1, 13)))));
        self::assertCount(2, $pages);
        self::assertCount(5, $this->column($pages[0], 10.0));
        self::assertCount(5, $this->column($pages[0], 120.0));
        self::assertSame(['L11', 'L12'], $this->column($pages[1], 10.0));
        self::assertSame(['L13'], $this->column($pages[1], 120.0));
    }

    public function test_forced_page_break_page_is_not_balanced(): void
    {
        $pages = $this->paginate(Document::make($this->page()->body($this->flow(...self::lines(1, 4), ...[PageBreak::make()], ...self::lines(5, 6)))));
        self::assertCount(2, $pages);
        self::assertSame(['L1', 'L2', 'L3', 'L4'], $this->column($pages[0], 10.0));
        self::assertSame([], $this->column($pages[0], 120.0));
        self::assertSame(['L5', 'L6'], [...$this->column($pages[1], 10.0), ...$this->column($pages[1], 120.0)]);
    }

    public function test_keep_together_block_limits_balance_but_nothing_is_lost(): void
    {
        $block = View::make(...self::lines(4, 6))->keepTogether();
        $pages = $this->paginate(Document::make($this->page()->body($this->flow(...self::lines(1, 3), ...[$block]))));
        self::assertCount(1, $pages);
        self::assertSame(['L1', 'L2', 'L3'], $this->column($pages[0], 10.0));
        self::assertSame(['L4', 'L5', 'L6'], $this->column($pages[0], 120.0));

        $pages = $this->paginate(Document::make($this->page()->body($this->flow(Text::make('L1'), ...[View::make(...self::lines(2, 5))->keepTogether()]))));
        self::assertCount(1, $pages);
        self::assertSame(['L1'], $this->column($pages[0], 10.0));
        self::assertSame(['L2', 'L3', 'L4', 'L5'], $this->column($pages[0], 120.0));
    }

    public function test_unbounded_flow_balances_from_its_total_height(): void
    {
        $box = View::make($this->flow(...self::lines(1, 6)))->height(pt(70));
        $pages = $this->paginate(Document::make($this->page()->body($box)));
        self::assertCount(1, $pages);
        self::assertSame(['L1', 'L2', 'L3'], $this->column($pages[0], 10.0));
        self::assertSame(['L4', 'L5', 'L6'], $this->column($pages[0], 120.0));
    }

    public function test_three_columns_balance(): void
    {
        $flow = ColumnFlow::make(...self::lines(1, 6))->columns(3)->columnGap(pt(20));
        $pages = $this->paginate(Document::make($this->page()->body($flow)));
        self::assertCount(1, $pages);
        $by = [];
        foreach ($this->xs($pages[0]) as $t => $x) {
            $by[(string) $x][] = $t;
        }
        self::assertSame([['L1', 'L2'], ['L3', 'L4'], ['L5', 'L6']], array_values($by));
    }

    public function test_three_columns_take_what_greedy_filling_allows(): void
    {
        $flow = ColumnFlow::make(...self::lines(1, 7))->columns(3)->columnGap(pt(20));
        $pages = $this->paginate(Document::make($this->page()->body($flow)));
        $by = [];
        foreach ($this->xs($pages[0]) as $t => $x) {
            $by[(string) $x][] = $t;
        }
        self::assertSame([['L1', 'L2', 'L3'], ['L4', 'L5', 'L6'], ['L7']], array_values($by));
    }

    public function test_column_break_content_is_balanced_within_what_breaks_allow(): void
    {
        $flow = $this->flow(...self::lines(1, 2), ...[ColumnBreak::make()], ...self::lines(3, 5));
        $pages = $this->paginate(Document::make($this->page()->body($flow)));
        self::assertSame(['L1', 'L2'], $this->column($pages[0], 10.0));
        self::assertSame(['L3', 'L4', 'L5'], $this->column($pages[0], 120.0));
    }

    public function test_column_break_in_the_last_column_of_an_unbounded_flow_loses_nothing(): void
    {
        $box = View::make($this->flow(Text::make('A'), ColumnBreak::make(), Text::make('B'), ColumnBreak::make(), Text::make('C')))->height(pt(60));
        $pages = $this->paginate(Document::make($this->page()->body($box)));
        // The flow is laid out unbounded, so its column break in the last column ends a "page" that can't
        // be a page: the enclosing box carries on with the rest below it. That page is cut short by a break,
        // so it isn't balanced (A and B keep their columns), and nothing is lost: C starts column one again.
        self::assertCount(1, $pages);
        $at = [];
        foreach ($pages[0]->ops as $o) {
            if ($o instanceof TextRun) {
                $at[$o->text] = [round($o->x, 3), $o->baseline];
            }
        }
        self::assertSame(['A', 'B', 'C'], array_keys($at));
        self::assertSame([10.0, 120.0, 10.0], [$at['A'][0], $at['B'][0], $at['C'][0]]);
        self::assertEqualsWithDelta($at['A'][1], $at['B'][1], 1e-9);
        self::assertGreaterThan($at['A'][1], $at['C'][1]);
    }

    public function test_rule_is_drawn_only_between_columns_with_content(): void
    {
        $flow = $this->flow(Text::make('L1'))->columnRule(pt(1), Color::black());
        $pages = $this->paginate(Document::make($this->page()->body($flow)));
        self::assertSame([], $this->rules($pages[0]));

        $flow = $this->flow(...self::lines(1, 4))->columnRule(pt(1), Color::black());
        $pages = $this->paginate(Document::make($this->page()->body($flow)));
        $rules = $this->rules($pages[0]);
        self::assertCount(1, $rules);
        self::assertEqualsWithDelta(28.0, $rules[0]->y2 - $rules[0]->y1, 1e-9);

        // three columns, content in the first only: no gap has content on both sides
        $flow = ColumnFlow::make(Text::make('L1'))->columns(3)->columnGap(pt(20))->columnRule(pt(1), Color::black());
        $pages = $this->paginate(Document::make($this->page()->body($flow)));
        self::assertSame([], $this->rules($pages[0]));
    }

    public function test_clip_mode_trials_do_not_clip_a_tall_first_child(): void
    {
        $flow = $this->flow(\Papercut\Image::bytes('x')->width(pt(50))->height(pt(60))->fit(\Papercut\Style\ImageFit::Fill), ...self::lines(1, 3));
        $pages = $this->paginate(Document::make($this->page()->body($flow)), \Papercut\OverflowMode::Clip);
        self::assertCount(1, $pages);
        self::assertSame(['L1', 'L2', 'L3'], $this->column($pages[0], 120.0));
        self::assertSame([], array_values(array_filter($this->warnings, fn (string $w) => str_contains($w, 'clipped'))));
        $images = array_values(array_filter($pages[0]->ops, fn ($o) => $o instanceof \Papercut\Paint\DrawImage));
        self::assertCount(1, $images);
        self::assertEqualsWithDelta(60.0, $images[0]->height, 1e-9);
    }

    public function test_kept_block_first_in_the_flow_stays_whole(): void
    {
        $flow = $this->flow(View::make(...self::lines(1, 4))->keepTogether(), ...self::lines(5, 6));
        $pages = $this->paginate(Document::make($this->page()->body($flow)));
        self::assertSame(['L1', 'L2', 'L3', 'L4'], $this->column($pages[0], 10.0));
        self::assertSame(['L5', 'L6'], $this->column($pages[0], 120.0));
    }

    public function test_trials_leave_no_warning_and_no_failure_path(): void
    {
        $this->paginate(Document::make($this->page()->body($this->flow(...self::lines(1, 7)))));
        self::assertSame([], $this->warnings);
        self::assertNull($this->lastCtx?->lastFailurePath);
    }
}
