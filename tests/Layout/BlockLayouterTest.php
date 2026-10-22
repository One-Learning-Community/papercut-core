<?php

declare(strict_types=1);

namespace Papercut\Tests\Layout;

use Papercut\{Column, Link, PageBreak, Spacer, Text, View};
use Papercut\Node;
use Papercut\Layout\{BreakToken, Constraints, Fragment, LayoutEngine, LayoutResult, Layouter, Status};
use Papercut\Style\ComputedStyle;
use Papercut\Paint\{Line, Link as LinkOp, Rect};
use Papercut\Style\Align;
use Papercut\Tests\Support\LayoutHarness;
use Papercut\Value\Color;
use PHPUnit\Framework\TestCase;

use function Papercut\{percent, pt};

final class BlockLayouterTest extends TestCase
{
    public function test_stacks_children_with_gap_padding_and_margin(): void
    {
        $col = Column::make(Text::make('a'), Text::make('b')->margin(pt(3), pt(0)))->gap(pt(4))->padding(pt(10));
        $r = (new LayoutHarness())->layout($col, 100);
        // 10 + 14 + 4 + 3 + 14 + 3 + 10
        self::assertSame(58.0, $r->fragment?->height);
        $runs = LayoutHarness::runs($r->fragment);
        self::assertSame([10.0, 20.0], [$runs[0]['x'], $runs[0]['baseline']]);
        self::assertSame(41.0, $runs[1]['baseline']); // 10 + 14 + 4 + 3 + 10
    }

    public function test_background_border_and_width(): void
    {
        $v = View::make('x')->width(percent(50))->background(Color::white())->border(pt(1), Color::black())->radius(pt(4));
        $f = (new LayoutHarness())->layout($v, 200)->fragment;
        self::assertSame(100.0, $f?->width);
        $rects = array_values(array_filter($f->ops(), fn ($o) => $o instanceof Rect));
        self::assertCount(2, $rects); // background + stroke
        self::assertSame(4.0, $rects[0]->radius);
    }

    public function test_empty_column(): void
    {
        $r = (new LayoutHarness())->layout(Column::make()->padding(pt(5)), 100);
        self::assertSame(Status::Complete, $r->status);
        self::assertSame(10.0, $r->fragment?->height);
    }

    public function test_breaks_between_children_and_resumes(): void
    {
        $col = Column::make(Text::make('one'), Text::make('two'), Text::make('three'));
        $h = new LayoutHarness();
        $r = $h->layout($col, 100, 30);
        self::assertSame(Status::Partial, $r->status);
        self::assertSame(2, $r->token?->index);
        $rest = $h->layout($col, 100, null, $r->token);
        self::assertSame(['three'], array_column(LayoutHarness::runs($rest->fragment), 'text'));
    }

    public function test_child_partial_propagates_token(): void
    {
        $col = Column::make(Text::make('a b c d e f')->minLines(1));
        $r = (new LayoutHarness())->layout($col, 5, 28);
        self::assertSame(0, $r->token?->index);
        self::assertSame(2, $r->token->child?->index);
    }

    public function test_keep_together_moves_whole_block(): void
    {
        $block = Column::make(Text::make('a'), Text::make('b'))->keepTogether();
        self::assertSame(Status::DoesNotFit, (new LayoutHarness())->layout($block, 100, 20, fresh: false)->status);
        self::assertSame(Status::Partial, (new LayoutHarness())->layout($block, 100, 20, fresh: true)->status);
    }

    public function test_keep_with_next_breaks_before_heading(): void
    {
        $col = Column::make(Text::make('intro'), Text::make('Heading')->keepWithNext(), Column::make(Text::make('x'), Text::make('y'))->keepTogether());
        $r = (new LayoutHarness())->layout($col, 100, 40);
        self::assertSame(1, $r->token?->index);
    }

    // --- Fix round 1 ---

    public function test_trailing_margin_bottom_is_truncated_at_page_end(): void
    {
        $h = new LayoutHarness();
        $one = $h->layout(Column::make(Text::make('a')->margin(pt(6), pt(0))), 100, 20);
        self::assertSame(Status::Complete, $one->status);
        self::assertSame(20.0, $one->fragment?->height);
        $two = $h->layout(Column::make(Text::make('a')->margin(pt(6), pt(0)), Text::make('b')), 100, 20);
        self::assertSame(Status::Partial, $two->status);
        self::assertSame(20.0, $two->fragment?->height);
        $bordered = Column::make(Text::make('a')->margin(pt(6), pt(0)), Text::make('b'))->border(pt(1), Color::black());
        $r = $h->layout($bordered, 100, 22);
        self::assertSame(Status::Partial, $r->status);
        self::assertLessThanOrEqual(22.0, $r->fragment?->height);
    }

    public function test_partial_child_gets_no_margin_bottom(): void
    {
        $col = Column::make(Text::make('a b c d')->minLines(1)->margin(pt(0), pt(0), pt(6)));
        $r = (new LayoutHarness())->layout($col, 5, 28);
        self::assertSame(Status::Partial, $r->status);
        self::assertSame(28.0, $r->fragment?->height);
    }

    public function test_keep_with_next_pop_restores_height(): void
    {
        $col = Column::make(Text::make('intro'), Text::make('Heading')->keepWithNext(), Spacer::size(pt(50)));
        $r = (new LayoutHarness())->layout($col, 100, 40);
        self::assertSame(1, $r->token?->index);
        self::assertSame(14.0, $r->fragment?->height);
        self::assertSame(['intro'], array_column(LayoutHarness::runs($r->fragment), 'text'));
    }

    public function test_keep_with_next_chain_walks_back(): void
    {
        $h = new LayoutHarness();
        $col = Column::make(Text::make('intro'), Text::make('H1')->keepWithNext(), Text::make('H2')->keepWithNext(), Spacer::size(pt(50)));
        $r = $h->layout($col, 100, 50);
        self::assertSame(1, $r->token?->index);
        self::assertSame(14.0, $r->fragment?->height);

        $headsOnly = Column::make(Text::make('H1')->keepWithNext(), Text::make('H2')->keepWithNext(), Spacer::size(pt(50)));
        self::assertSame(Status::DoesNotFit, $h->layout($headsOnly, 100, 40, fresh: false)->status);
        $fresh = $h->layout($headsOnly, 100, 40, fresh: true);
        self::assertSame(Status::Partial, $fresh->status);
        self::assertSame(2, $fresh->token?->index);
    }

    public function test_trailing_page_break_in_nested_block_breaks_parent(): void
    {
        $h = new LayoutHarness();
        $col = Column::make(Column::make(Text::make('a'), PageBreak::make()), Text::make('b'));
        $r = $h->layout($col, 100, 500);
        self::assertSame(Status::Partial, $r->status);
        self::assertSame(['a'], array_column(LayoutHarness::runs($r->fragment), 'text'));
        $rest = $h->layout($col, 100, 500, $r->token);
        self::assertSame(['b'], array_column(LayoutHarness::runs($rest->fragment), 'text'));
        self::assertSame(Status::Complete, $rest->status);
    }

    public function test_trailing_break_after_in_nested_block_breaks_parent(): void
    {
        $h = new LayoutHarness();
        $col = Column::make(Column::make(Text::make('a'), Column::make(Text::make('b')->breakAfter())), Text::make('c'));
        $r = $h->layout($col, 100, 500);
        self::assertSame(Status::Partial, $r->status);
        self::assertSame(1, $r->token?->index);
        self::assertSame(['a', 'b'], array_column(LayoutHarness::runs($r->fragment), 'text'));
    }

    public function test_resumed_child_drops_margin_top(): void
    {
        $col = Column::make(Text::make('a b c d')->minLines(1)->margin(pt(6), pt(0)));
        $r = (new LayoutHarness())->layout($col, 5, null, new BreakToken(index: 0, child: new BreakToken(index: 2)));
        self::assertSame(10.0, LayoutHarness::runs($r->fragment)[0]['baseline']);
    }

    public function test_stretched_auto_height_fills_but_stays_splittable(): void
    {
        $h = new LayoutHarness();
        $root = \Papercut\Style\ComputedStyle::root(\Papercut\Style\Theme::default());
        $col = Column::make(Text::make('a'), Text::make('b'), Text::make('c'));
        $split = $h->engine->layout($col, $root, new \Papercut\Layout\Constraints(100, 30, true, 100));
        self::assertSame(Status::Partial, $split->status);
        $full = $h->engine->layout($col, $root, new \Papercut\Layout\Constraints(100, null, true, 100));
        self::assertSame(100.0, $full->fragment?->height);
        $tall = $h->engine->layout($col, $root, new \Papercut\Layout\Constraints(100, null, true, 20));
        self::assertSame(42.0, $tall->fragment?->height); // at least definiteHeight, never less than content
    }

    public function test_page_break_and_break_before(): void
    {
        $h = new LayoutHarness();
        $r = $h->layout(Column::make(Text::make('a'), PageBreak::make(), Text::make('b')), 100, 500);
        self::assertSame(Status::Partial, $r->status);
        self::assertSame(['a'], array_column(LayoutHarness::runs($r->fragment), 'text'));
        $r2 = $h->layout(Column::make(Text::make('a'), Text::make('b')->breakBefore()), 100, 500);
        self::assertSame(1, $r2->token?->index);
        $r3 = $h->layout(Column::make(PageBreak::make(), Text::make('a')), 100, 500);
        self::assertSame(Status::Complete, $r3->status);
    }

    public function test_sliced_borders_on_split_box(): void
    {
        $box = Column::make(Text::make('a'), Text::make('b'))->borderWidth(pt(1))->borderColor(Color::black());
        $r = (new LayoutHarness())->layout($box, 100, 20);
        $lines = array_filter($r->fragment?->ops() ?? [], fn ($o) => $o instanceof Line);
        self::assertCount(3, $lines); // top, left, right; no bottom on first slice
    }

    public function test_align_items_center_sizes_child_to_content(): void
    {
        $col = Column::make(Text::make('ab'))->align(Align::Center);
        $runs = LayoutHarness::runs((new LayoutHarness())->layout($col, 100)->fragment);
        self::assertSame(45.0, $runs[0]['x']);
    }

    public function test_link_wraps_content(): void
    {
        $f = (new LayoutHarness())->layout(Link::to('https://x.test', 'go'), 100)->fragment;
        $links = array_values(array_filter($f?->ops() ?? [], fn ($o) => $o instanceof LinkOp));
        self::assertSame('https://x.test', $links[0]->uri);
    }

    public function test_fixed_height_grow_and_justify(): void
    {
        $col = Column::make(Text::make('a'), Spacer::flex(), Text::make('b'))->height(pt(100));
        $runs = LayoutHarness::runs((new LayoutHarness())->layout($col, 100)->fragment);
        self::assertSame(96.0, $runs[1]['baseline']); // pushed to bottom: 100 - 14 + 10
    }

    // --- Additional coverage beyond the brief ---

    public function test_paths_carry_sibling_index_and_computed_direction(): void
    {
        $h = new LayoutHarness();
        $h->layout(Column::make(Text::make('a'), Text::make('b'), View::make(Text::make("\u{65E5}"))), 100);
        self::assertSame(["[column > column[2] > text[0]] Missing glyphs for \"\u{65E5}\" in font family Inter"], $h->warnings);
        self::assertSame('', $h->engine->context()->path());
    }

    public function test_resume_from_child_token_continues_partial_child(): void
    {
        $col = Column::make(Text::make('z'), Text::make('a b c d e f')->minLines(1));
        $h = new LayoutHarness();
        $r = $h->layout($col, 5, null, new BreakToken(index: 1, child: new BreakToken(index: 4)));
        self::assertSame(['e', 'f'], array_column(LayoutHarness::runs($r->fragment), 'text'));
        self::assertSame(28.0, $r->fragment?->height);
    }

    public function test_break_after_and_trailing_children(): void
    {
        $h = new LayoutHarness();
        $r = $h->layout(Column::make(Text::make('a')->breakAfter(), Text::make('b')), 100, 500);
        self::assertSame(1, $r->token?->index);
        $last = $h->layout(Column::make(Text::make('a'), Text::make('b')->breakAfter()), 100, 500);
        self::assertSame(Status::Complete, $last->status);
    }

    public function test_page_break_first_on_non_fresh_page_pushes_block(): void
    {
        $r = (new LayoutHarness())->layout(Column::make(PageBreak::make(), Text::make('a')), 100, 500, fresh: false);
        self::assertSame(Status::DoesNotFit, $r->status);
    }

    public function test_first_child_not_fitting_returns_does_not_fit(): void
    {
        $h = new LayoutHarness();
        self::assertSame(Status::DoesNotFit, $h->layout(Column::make(Spacer::size(pt(50))), 100, 20, fresh: false)->status);
        $fresh = $h->layout(Column::make(Spacer::size(pt(50))), 100, 20, fresh: true);
        self::assertSame(Status::Complete, $fresh->status);
        self::assertSame(20.0, $fresh->fragment?->height); // truncated on a fresh page
    }

    public function test_keep_with_next_on_first_child_of_page_is_ignored(): void
    {
        $col = Column::make(Text::make('Heading')->keepWithNext(), Spacer::size(pt(50)), Text::make('x'));
        $r = (new LayoutHarness())->layout($col, 100, 40, fresh: true);
        self::assertSame(Status::Partial, $r->status);
        self::assertSame(1, $r->token?->index);
    }

    public function test_vertical_padding_and_border_count_against_height(): void
    {
        $col = Column::make(Text::make('a'), Text::make('b'))->padding(pt(5));
        $r = (new LayoutHarness())->layout($col, 100, 33);
        self::assertSame(Status::Partial, $r->status);
        self::assertSame(19.0, $r->fragment?->height); // top padding + one line, no bottom padding on first slice
        $rest = (new LayoutHarness())->layout($col, 100, null, $r->token);
        self::assertSame(19.0, $rest->fragment?->height); // one line + bottom padding
    }

    public function test_dashed_and_partial_borders_emit_lines(): void
    {
        $v = View::make('x')->border(pt(2), Color::black(), \Papercut\Style\BorderStyle::Dashed);
        $ops = (new LayoutHarness())->layout($v, 100)->fragment?->ops() ?? [];
        $lines = array_values(array_filter($ops, fn ($o) => $o instanceof Line));
        self::assertCount(4, $lines);
        self::assertTrue($lines[0]->dashed);
    }

    public function test_opacity_and_explicit_width_and_justify_end(): void
    {
        $col = Column::make(Text::make('a'))->height(pt(50))->justify(\Papercut\Style\Justify::End)->opacity(0.5)->width(pt(60));
        $r = (new LayoutHarness())->layout($col, 100);
        self::assertSame(0.5, $r->fragment?->opacity);
        self::assertSame(60.0, $r->fragment->width);
        self::assertSame(46.0, LayoutHarness::runs($r->fragment)[0]['baseline']); // 50 - 14 + 10
    }

    public function test_fixed_height_box_taller_than_space_does_not_fit(): void
    {
        $col = Column::make(Text::make('a'))->height(pt(100));
        self::assertSame(Status::DoesNotFit, (new LayoutHarness())->layout($col, 100, 50, fresh: true)->status);
    }

    public function test_intrinsic_sizes(): void
    {
        $h = new LayoutHarness();
        $root = \Papercut\Style\ComputedStyle::root(\Papercut\Style\Theme::default());
        $col = Column::make(Text::make('ab cdef'), Text::make('xyz')->margin(pt(0), pt(2)))->padding(pt(1));
        self::assertSame(['min' => 22.0, 'max' => 37.0], $h->engine->intrinsic($col, $root));
        self::assertSame(['min' => 80.0, 'max' => 80.0], $h->engine->intrinsic($col->width(pt(80)), $root));
    }

    public function test_child_end_alignment_with_align_self(): void
    {
        $col = Column::make(Text::make('ab')->alignSelf(Align::End));
        $runs = LayoutHarness::runs((new LayoutHarness())->layout($col, 100)->fragment);
        self::assertSame(90.0, $runs[0]['x']);
    }

    public function test_fixed_height_box_never_returns_partial_for_nested_forced_break(): void
    {
        $col = Column::make(Column::make(Text::make('a'), PageBreak::make(), Text::make('b')))->height(pt(100));
        $r = (new LayoutHarness())->layout($col, 100, 500);
        self::assertSame(Status::Complete, $r->status);
        self::assertSame(['a', 'b'], array_column(LayoutHarness::runs($r->fragment), 'text'));
    }

    // --- Fix round 2: forced breaks inside explicit-height boxes are satisfied in place ---

    /** @return iterable<string, array{\Papercut\View, list<string>}> */
    public static function inPlaceBoxes(): iterable
    {
        yield 'nested breakBefore' => [Column::make(Column::make(Text::make('a'), Text::make('b')->breakBefore()))->height(pt(100)), ['a', 'b']];
        yield 'double page break' => [Column::make(Column::make(Text::make('a'), PageBreak::make(), PageBreak::make(), Text::make('b')))->height(pt(100)), ['a', 'b']];
        yield 'not first in parent' => [Column::make(Text::make('x'), Column::make(Column::make(Text::make('a'), Text::make('b')->breakBefore()))->height(pt(100))), ['x', 'a', 'b']];
        yield 'nested breakAfter' => [Column::make(Column::make(Text::make('a')->breakAfter(), Text::make('b')))->height(pt(100)), ['a', 'b']];
    }

    /** @param list<string> $texts */
    #[\PHPUnit\Framework\Attributes\DataProvider('inPlaceBoxes')]
    public function test_fixed_height_box_satisfies_nested_breaks_in_place(\Papercut\View $box, array $texts): void
    {
        $r = (new LayoutHarness())->layout($box, 100, 500);
        self::assertSame(Status::Complete, $r->status);
        self::assertSame($texts, array_column(LayoutHarness::runs($r->fragment), 'text'));
    }

    public function test_in_place_slices_are_not_separated_by_gap(): void
    {
        $box = Column::make(Column::make(Text::make('a'), PageBreak::make(), Text::make('b')))->height(pt(100))->gap(pt(10));
        $runs = LayoutHarness::runs((new LayoutHarness())->layout($box, 100, 500)->fragment);
        self::assertSame([10.0, 24.0], array_column($runs, 'baseline'));
    }

    // --- Task 13 ---------------------------------------------------------

    private static function scripted(LayoutResult ...$results): LayoutHarness
    {
        $h = new LayoutHarness();
        $h->engine->register(ScriptedNode::class, new class ($results) implements Layouter {
            private int $calls = 0;

            /** @param array<LayoutResult> $results */
            public function __construct(private readonly array $results)
            {
            }

            public function layout(Node $node, ComputedStyle $style, Constraints $c, ?BreakToken $token, LayoutEngine $engine): LayoutResult
            {
                $results = array_values($this->results);
                return $results[min($this->calls++, count($results) - 1)];
            }

            public function intrinsic(Node $node, ComputedStyle $style, LayoutEngine $engine): array
            {
                return ['min' => 0.0, 'max' => 0.0];
            }
        });
        return $h;
    }

    public function test_in_place_continuation_drops_a_slice_that_makes_no_progress(): void
    {
        // Partial with the same token every time: the repeated slice is not appended.
        $h = self::scripted(LayoutResult::partial(new Fragment(100, 10.0), new BreakToken(index: 1)));
        $r = $h->layout(Column::make(new ScriptedNode())->height(pt(100)), 100);
        self::assertSame(Status::Complete, $r->status);
        self::assertSame(10.0, $r->fragment?->children()[0]['fragment']->height);
    }

    public function test_child_that_does_not_fit_inside_a_fixed_height_box_warns(): void
    {
        $h = self::scripted(LayoutResult::doesNotFit());
        $r = $h->layout(Column::make(Text::make('a'), new ScriptedNode())->height(pt(100)), 100);
        self::assertSame(Status::Complete, $r->status);
        self::assertSame(['[column] Content dropped inside fixed-height box: scripted[1]'], $h->warnings);
    }

    // --- Final review: keepWithNext across containers ---

    public function test_keep_with_next_at_end_of_nested_container_moves_with_next_sibling(): void
    {
        $h = new LayoutHarness();
        $col = Column::make(Column::make(Text::make('intro'), Text::make('Heading')->keepWithNext()), Spacer::size(pt(50)));
        $r = $h->layout($col, 100, 40);
        self::assertSame(Status::Partial, $r->status);
        self::assertSame(['intro'], array_column(LayoutHarness::runs($r->fragment), 'text'));
        self::assertSame(0, $r->token?->index);
        self::assertSame(1, $r->token->child?->index);
        $rest = $h->layout($col, 100, null, $r->token);
        self::assertSame(['Heading'], array_column(LayoutHarness::runs($rest->fragment), 'text'));
    }

    public function test_keep_with_next_chain_in_deeply_nested_container(): void
    {
        $h = new LayoutHarness();
        $inner = Column::make(Text::make('a'), Column::make(Text::make('b'), Text::make('H1')->keepWithNext(), Text::make('H2')->keepWithNext()));
        $col = Column::make($inner, Spacer::size(pt(50)));
        $r = $h->layout($col, 100, 60);
        self::assertSame(['a', 'b'], array_column(LayoutHarness::runs($r->fragment), 'text'));
        $rest = $h->layout($col, 100, null, $r->token);
        self::assertSame(['H1', 'H2'], array_column(LayoutHarness::runs($rest->fragment), 'text'));
    }

    public function test_nested_container_that_is_only_keep_with_next_joins_the_chain(): void
    {
        $h = new LayoutHarness();
        $col = Column::make(Text::make('intro'), Column::make(Text::make('Heading')->keepWithNext()), Spacer::size(pt(50)));
        $r = $h->layout($col, 100, 40);
        self::assertSame(1, $r->token?->index);
        self::assertNull($r->token->child);
        self::assertSame(['intro'], array_column(LayoutHarness::runs($r->fragment), 'text'));
        $alone = Column::make(Column::make(Text::make('Heading')->keepWithNext()), Spacer::size(pt(50)));
        self::assertSame(Status::DoesNotFit, $h->layout($alone, 100, 40, fresh: false)->status);
    }
}

final class ScriptedNode implements Node
{
    public function debugName(): string
    {
        return 'scripted';
    }

    public function style(): \Papercut\Style\Style
    {
        return new \Papercut\Style\Style();
    }
}
