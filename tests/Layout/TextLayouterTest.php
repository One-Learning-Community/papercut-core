<?php

declare(strict_types=1);

namespace Papercut\Tests\Layout;

use Papercut\{Component, Node, Span, Text, View};
use Papercut\Exception\LayoutOverflow;
use Papercut\Layout\{BreakToken, Constraints, Fragment, LayoutResult, Status};
use Papercut\Paint\{Line as LineOp, Link as LinkOp, Rect, TextRun};
use Papercut\PapercutException;
use Papercut\Style\{ComputedStyle, Style, Theme};
use Papercut\Tests\Support\LayoutHarness;
use Papercut\Value\Color;
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

final class TextLayouterTest extends TestCase
{
    public function test_single_line_geometry(): void
    {
        $r = (new LayoutHarness())->layout(Text::make('Hello world'), 200);
        self::assertSame(Status::Complete, $r->status);
        self::assertSame(14.0, $r->fragment?->height);
        $runs = LayoutHarness::runs($r->fragment);
        self::assertSame([['text' => 'Hello world', 'x' => 0.0, 'baseline' => 10.0]], $runs);
    }

    public function test_alignment(): void
    {
        $h = new LayoutHarness();
        self::assertSame(145.0, LayoutHarness::runs($h->layout(Text::make('Hello world')->right(), 200)->fragment)[0]['x']);
        self::assertSame(72.5, LayoutHarness::runs($h->layout(Text::make('Hello world')->center(), 200)->fragment)[0]['x']);
    }

    public function test_justify_spreads_words_except_last_line(): void
    {
        $runs = LayoutHarness::runs((new LayoutHarness())->layout(Text::make('aa bb ccc dd')->justify(), 40)->fragment);
        // line 1 "aa bb" (25pt) justified to 40 -> "bb" ends at 40; line 2 "ccc dd" left-aligned merged
        self::assertSame('aa', $runs[0]['text']);
        self::assertSame(30.0, $runs[1]['x']);
        self::assertSame('ccc dd', $runs[2]['text']);
    }

    public function test_justify_skips_lines_ending_in_a_break(): void
    {
        $t = Text::spans(Span::text('aa bb'), Span::break(), Span::text('cc dd ee ff'))->justify();
        $runs = LayoutHarness::runs((new LayoutHarness())->layout($t, 40)->fragment);
        self::assertSame(['aa bb', 'cc', 'dd', 'ee', 'ff'], array_column($runs, 'text'));
        // "cc dd ee" = 40 exactly, so no extra space is distributed
        self::assertSame(15.0, $runs[2]['x']);
        self::assertSame(0.0, $runs[4]['x']);
    }

    public function test_wraps_and_height(): void
    {
        $r = (new LayoutHarness())->layout(Text::make('aaa bbb ccc'), 35);
        self::assertSame(28.0, $r->fragment?->height);
        self::assertSame(24.0, LayoutHarness::runs($r->fragment)[1]['baseline']);
    }

    public function test_empty_text_is_zero_height(): void
    {
        $r = (new LayoutHarness())->layout(Text::make(''), 100);
        self::assertSame(Status::Complete, $r->status);
        self::assertSame(0.0, $r->fragment?->height);
        self::assertSame(100.0, $r->fragment->width);
    }

    public function test_empty_line_from_break_uses_paragraph_line_box(): void
    {
        $t = Text::spans(Span::text('a'), Span::break(), Span::break(), Span::text('b'));
        $r = (new LayoutHarness())->layout($t, 100);
        self::assertSame(42.0, $r->fragment?->height);
        self::assertSame(38.0, LayoutHarness::runs($r->fragment)[1]['baseline']);
    }

    public function test_mixed_sizes_share_a_baseline(): void
    {
        $t = Text::spans(Span::text('a '), Span::text('B', Style::make(fontSize: pt(20))));
        $r = (new LayoutHarness())->layout($t, 100);
        // 20pt item: half = (28 - 20) / 2 = 4, baseline = 4 + 16 = 20, bottom = 20 + 4 + 4 = 28
        self::assertSame(28.0, $r->fragment?->height);
        $runs = LayoutHarness::runs($r->fragment);
        self::assertSame([20.0, 20.0], array_column($runs, 'baseline'));
        self::assertSame(['a ', 'B'], array_column($runs, 'text'));
    }

    public function test_fragmentation_respects_orphans_and_widows(): void
    {
        $text = Text::make('a b c d e f'); // width 5 -> 6 lines of 14pt
        $h = new LayoutHarness();
        // 3 lines fit (42pt): break after 3, 3 remain -> fine
        $r = $h->layout($text, 5, 42, fresh: false);
        self::assertSame(Status::Partial, $r->status);
        self::assertSame(3, $r->token?->index);
        // 5 lines fit (70pt): widows would leave 1 -> pull back to 4
        $r = $h->layout($text, 5, 70, fresh: false);
        self::assertSame(4, $r->token?->index);
        // 1 line fits, not fresh -> DoesNotFit (orphan)
        self::assertSame(Status::DoesNotFit, $h->layout($text, 5, 14, fresh: false)->status);
        // 1 line fits, fresh -> place 1 anyway
        self::assertSame(1, $h->layout($text, 5, 14, fresh: true)->token?->index);
        // resume from token
        $rest = $h->layout($text, 5, null, new BreakToken(index: 4));
        self::assertSame(Status::Complete, $rest->status);
        self::assertCount(2, LayoutHarness::runs($rest->fragment));
    }

    public function test_fragment_heights_and_resumed_positions(): void
    {
        $h = new LayoutHarness();
        $text = Text::make('a b c d e f');
        $first = $h->layout($text, 5, 42, fresh: false);
        self::assertSame(42.0, $first->fragment?->height);
        $rest = $h->layout($text, 5, 100, $first->token);
        self::assertSame(Status::Complete, $rest->status);
        self::assertSame(['d', 'e', 'f'], array_column(LayoutHarness::runs($rest->fragment), 'text'));
        self::assertSame(10.0, LayoutHarness::runs($rest->fragment)[0]['baseline']);
    }

    public function test_nothing_fits_on_fresh_page_places_one_line(): void
    {
        $r = (new LayoutHarness())->layout(Text::make('a b c'), 5, 3, fresh: true);
        self::assertSame(Status::Partial, $r->status);
        self::assertSame(1, $r->token?->index);
        self::assertSame(14.0, $r->fragment?->height);
    }

    public function test_widow_pullback_below_min_lines(): void
    {
        $h = new LayoutHarness();
        // 3 lines, 2 fit: widows leave 1 -> n = 1 < 2 -> DoesNotFit when not fresh, 1 line when fresh
        self::assertSame(Status::DoesNotFit, $h->layout(Text::make('a b c'), 5, 28, fresh: false)->status);
        $fresh = $h->layout(Text::make('a b c'), 5, 28, fresh: true);
        self::assertSame(Status::Partial, $fresh->status);
        self::assertSame(1, $fresh->token?->index);
    }

    public function test_min_lines_capped_by_line_count(): void
    {
        // 2 lines with minLines 5 -> effective 2; only 1 fits, not fresh -> DoesNotFit; all fit -> Complete
        $h = new LayoutHarness();
        self::assertSame(Status::DoesNotFit, $h->layout(Text::make('a b')->minLines(5), 5, 14, fresh: false)->status);
        self::assertSame(Status::Complete, $h->layout(Text::make('a b')->minLines(5), 5, 28, fresh: false)->status);
    }

    public function test_min_lines_one_allows_any_break(): void
    {
        $r = (new LayoutHarness())->layout(Text::make('a b c')->minLines(1), 5, 14, fresh: false);
        self::assertSame(1, $r->token?->index);
    }

    public function test_decorations_and_links(): void
    {
        $t = Text::spans(new Span('go', Style::make(underline: true), href: 'https://x.test'));
        $f = (new LayoutHarness())->layout($t, 100)->fragment;
        $ops = $f?->ops() ?? [];
        $lines = array_values(array_filter($ops, fn ($o) => $o instanceof LineOp));
        self::assertCount(1, $lines);
        self::assertEqualsWithDelta(11.2, $lines[0]->y1, 1e-9);
        self::assertEqualsWithDelta(11.2, $lines[0]->y2, 1e-9);
        self::assertSame(0.0, $lines[0]->x1);
        self::assertSame(10.0, $lines[0]->x2);
        self::assertEqualsWithDelta(0.6, $lines[0]->width, 1e-9);
        $links = array_values(array_filter($ops, fn ($o) => $o instanceof LinkOp));
        self::assertSame('https://x.test', $links[0]->uri);
        self::assertSame(10.0, $links[0]->width);
        self::assertSame(0.0, $links[0]->y);
        self::assertSame(14.0, $links[0]->height);
    }

    public function test_strike_and_minimum_decoration_width(): void
    {
        $t = Text::make('ab')->strike()->size(pt(5));
        $ops = (new LayoutHarness())->layout($t, 100)->fragment?->ops() ?? [];
        $lines = array_values(array_filter($ops, fn ($o) => $o instanceof LineOp));
        self::assertCount(1, $lines);
        // 5pt: half = (7 - 5) / 2 = 1, baseline = 1 + 4 = 5, strike at 5 - 1.5 = 3.5
        self::assertEqualsWithDelta(3.5, $lines[0]->y1, 1e-9);
        self::assertSame(0.5, $lines[0]->width);
    }

    public function test_links_split_per_line_and_per_href(): void
    {
        $t = Text::spans(
            new Span('aa bb', href: 'https://a.test'),
            Span::text(' cc'),
            new Span(' dd', href: 'https://d.test'),
        );
        $ops = (new LayoutHarness())->layout($t, 25)->fragment?->ops() ?? [];
        $links = array_values(array_filter($ops, fn ($o) => $o instanceof LinkOp));
        self::assertSame(
            [['https://a.test', 0.0, 0.0, 25.0], ['https://d.test', 10.0, 14.0, 15.0]],
            array_map(fn (LinkOp $l) => [$l->uri, $l->x, $l->y, $l->width], $links),
        );
    }

    public function test_highlighted_span_paints_a_rect_per_line_before_its_text(): void
    {
        $yellow = Color::named('yellow-200');
        $t = Text::spans(Span::text('aa '), Span::text('bb cc', Style::make(background: $yellow)));
        $ops = (new LayoutHarness())->layout($t, 25)->fragment?->ops() ?? [];
        $rects = array_values(array_filter($ops, fn ($o) => $o instanceof Rect));
        self::assertCount(2, $rects);
        self::assertSame((string) $yellow, (string) $rects[0]->fill);
        self::assertSame([15.0, 0.0, 10.0, 14.0], [$rects[0]->x, $rects[0]->y, $rects[0]->width, $rects[0]->height]);
        self::assertSame([0.0, 14.0, 10.0, 14.0], [$rects[1]->x, $rects[1]->y, $rects[1]->width, $rects[1]->height]);
        foreach ($rects as $r) {
            $at = array_search($r, $ops, true);
            self::assertInstanceOf(TextRun::class, $ops[$at + 1]);
        }
    }

    public function test_transparent_span_background_paints_no_rect(): void
    {
        $t = Text::spans(Span::text('aa '), Span::text('bb', Style::make(background: Color::transparent())));
        $ops = (new LayoutHarness())->layout($t, 100)->fragment?->ops() ?? [];
        self::assertSame([], array_values(array_filter($ops, fn ($o) => $o instanceof Rect)));
    }

    public function test_text_without_backgrounds_paints_no_rects(): void
    {
        $ops = (new LayoutHarness())->layout(Text::make('aa bb cc'), 25)->fragment?->ops() ?? [];
        self::assertSame([], array_values(array_filter($ops, fn ($o) => $o instanceof Rect)));
    }

    public function test_runs_split_on_style_change(): void
    {
        $t = Text::spans(Span::text('aa '), Span::text('bb', Style::make(color: Color::parse('red-500'))), Span::text(' cc'));
        $runs = LayoutHarness::runs((new LayoutHarness())->layout($t, 100)->fragment);
        self::assertSame(['aa ', 'bb', ' cc'], array_column($runs, 'text'));
        self::assertSame([0.0, 15.0, 25.0], array_column($runs, 'x'));
    }

    public function test_heading_preset_from_theme(): void
    {
        $r = (new LayoutHarness())->layout(Text::h1('T'), 100);
        // 24pt * 1.4 line height; margin is applied by the parent block (not inside text)
        self::assertEqualsWithDelta(33.6, $r->fragment->height ?? 0, 1e-9);
    }

    public function test_style_resolution_order_preset_then_named_then_own(): void
    {
        $theme = Theme::default()->withStyle('big', Style::make(fontSize: pt(20)));
        $h = new LayoutHarness($theme);
        self::assertEqualsWithDelta(28.0, $h->layout(Text::h1('T')->apply('big'), 100)->fragment->height ?? 0, 1e-9);
        self::assertEqualsWithDelta(42.0, $h->layout(Text::h1('T')->apply('big')->size(pt(30)), 100)->fragment->height ?? 0, 1e-9);
    }

    public function test_intrinsic_sizes(): void
    {
        $h = new LayoutHarness();
        $s = $h->engine->intrinsic(Text::make('aa bbbb c'), \Papercut\Style\ComputedStyle::root(\Papercut\Style\Theme::default()));
        self::assertSame(20.0, $s['min']);
        self::assertSame(45.0, $s['max']);
    }

    public function test_layout_cache_distinguishes_widths(): void
    {
        $h = new LayoutHarness();
        $t = Text::make('aaa bbb ccc');
        foreach ([[100, 14.0], [35, 28.0], [100, 14.0]] as [$width, $height]) {
            self::assertSame($height, $h->layout($t, $width)->fragment?->height);
        }
    }

    // --- layout primitives -------------------------------------------------

    public function test_components_expand_until_a_node(): void
    {
        $inner = new class () implements Component {
            public function render(): Node|Component
            {
                return Text::make('Hi');
            }
        };
        $outer = new class ($inner) implements Component {
            public function __construct(private readonly Component $inner)
            {
            }

            public function render(): Node|Component
            {
                return $this->inner;
            }
        };
        $r = (new LayoutHarness())->layout($outer, 100);
        self::assertSame('Hi', LayoutHarness::runs($r->fragment)[0]['text']);
    }

    public function test_self_rendering_component_hits_depth_limit(): void
    {
        $loop = new class () implements Component {
            public function render(): Node|Component
            {
                return $this;
            }
        };
        $this->expectException(\LogicException::class);
        (new LayoutHarness())->layout($loop, 100);
    }

    public function test_unregistered_node_names_computed_view_direction_in_path(): void
    {
        $h = new LayoutHarness();
        try {
            $h->layout(View::make(\Papercut\TableRow::make())->attributes(['direction' => 'row']), 100);
            self::fail('Expected LogicException');
        } catch (\LogicException $e) {
            self::assertStringContainsString('"row > tr[0]"', $e->getMessage());
        }
        try {
            $h->layout(\Papercut\TableRow::make(), 100);
            self::fail('Expected LogicException');
        } catch (\LogicException $e) {
            self::assertStringContainsString('"tr"', $e->getMessage());
        }
    }

    public function test_warnings_are_prefixed_with_node_path(): void
    {
        $h = new LayoutHarness();
        $h->layout(Text::make("\u{65E5}"), 100);
        self::assertSame(["[text] Missing glyphs for \"\u{65E5}\" in font family Inter"], $h->warnings);
    }

    public function test_context_path_stack(): void
    {
        $h = new LayoutHarness();
        $ctx = $h->engine->context();
        $ctx->pushPath('page');
        $ctx->pushPath('view[2]');
        $ctx->pushPath('text');
        self::assertSame('page > view[2] > text', $ctx->path());
        $ctx->warn('hello');
        $ctx->popPath();
        $ctx->popPath();
        $ctx->popPath();
        self::assertSame('', $ctx->path());
        self::assertSame(['[page > view[2] > text] hello'], $h->warnings);
    }

    public function test_path_is_popped_after_layout(): void
    {
        $h = new LayoutHarness();
        $h->layout(Text::make('x'), 100);
        self::assertSame('', $h->engine->context()->path());
    }

    public function test_context_caches_images_by_source(): void
    {
        $ctx = (new LayoutHarness())->engine->context();
        $a = $ctx->image(\Papercut\Image::file('/tmp/a.png'));
        self::assertSame($a, $ctx->image(\Papercut\Image::file('/tmp/a.png')));
        self::assertNotSame($a, $ctx->image(\Papercut\Image::bytes('/tmp/a.png')));
    }

    public function test_layout_overflow_message(): void
    {
        $e = new LayoutOverflow('page > column > image', 'content is taller than the page body');
        self::assertInstanceOf(PapercutException::class, $e);
        self::assertSame('Content at "page > column > image" cannot fit on an empty page: content is taller than the page body.', $e->getMessage());
    }

    public function test_constraints_helpers(): void
    {
        $c = new Constraints(100, 50, false, 30);
        self::assertSame(80.0, $c->withWidth(80)->width);
        self::assertSame(50.0, $c->withWidth(80)->height);
        self::assertNull($c->withHeight(null)->height);
        self::assertTrue($c->withFresh(true)->fresh);
        $u = $c->unbounded();
        self::assertNull($u->height);
        self::assertSame(100.0, $u->width);
        self::assertFalse($u->fresh);
        self::assertSame(30.0, $u->definiteHeight);
    }

    public function test_fragment_and_result_primitives(): void
    {
        $child = new Fragment(10, 5);
        $f = new Fragment(100, 50);
        $op = new Rect(0, 0, 1, 1);
        $f->addOp($op);
        $f->addChild(3, 4, $child);
        self::assertSame([$op], $f->ops());
        self::assertSame([['x' => 3.0, 'y' => 4.0, 'fragment' => $child]], $f->children());
        self::assertSame([], $f->slots());
        self::assertNull($f->opacity);
        self::assertNull($f->clip);

        $p = LayoutResult::partial($f, new BreakToken(2));
        self::assertSame(Status::Partial, $p->status);
        self::assertSame(2, $p->token?->index);
        self::assertSame(Status::Complete, LayoutResult::complete($f)->status);
        $d = LayoutResult::doesNotFit();
        self::assertSame(Status::DoesNotFit, $d->status);
        self::assertNull($d->fragment);
        self::assertNull($d->token);
    }

    public function test_resolve_style_applies_text_preset(): void
    {
        $ctx = (new LayoutHarness())->engine->context();
        $s = $ctx->resolveStyle(Text::h2('x'), ComputedStyle::root(Theme::default()));
        self::assertSame(18.0, $s->fontSize);
        self::assertSame(700, $s->fontWeight);
    }

    public function test_box_styles_pad_and_decorate_text(): void
    {
        $r = (new LayoutHarness())->layout(Text::make('ab')->padding(pt(5))->background(Color::white()), 100);
        $f = $r->fragment;
        self::assertNotNull($f);
        self::assertSame([100.0, 24.0], [$f->width, $f->height]);
        $runs = LayoutHarness::runs($f);
        self::assertSame([5.0, 15.0], [$runs[0]['x'], $runs[0]['baseline']]);
        $rects = array_values(array_filter($f->ops(), fn ($o) => $o instanceof Rect));
        self::assertCount(1, $rects);
        self::assertSame([0.0, 0.0, 100.0, 24.0], [$rects[0]->x, $rects[0]->y, $rects[0]->width, $rects[0]->height]);
    }

    public function test_split_padded_text_slices_padding(): void
    {
        $t = Text::make('a b c d')->minLines(1)->padding(pt(5));
        $h = new LayoutHarness();
        $r = $h->layout($t, 15, 33);
        self::assertSame(Status::Partial, $r->status);
        self::assertSame(1, $r->token?->index);
        self::assertSame(19.0, $r->fragment?->height); // top padding + 1 line; bottom padding reserved but not drawn
        $rest = $h->layout($t, 15, null, $r->token);
        self::assertSame(47.0, $rest->fragment?->height); // 3 lines + bottom padding
        self::assertSame(10.0, LayoutHarness::runs($rest->fragment)[0]['baseline']);
    }

    public function test_text_width_border_and_intrinsic_box(): void
    {
        $h = new LayoutHarness();
        $f = $h->layout(Text::make('ab')->width(pt(50))->border(pt(1), Color::black()), 100)->fragment;
        self::assertSame(50.0, $f?->width);
        self::assertSame(16.0, $f->height);
        self::assertSame(1.0, LayoutHarness::runs($f)[0]['x']);
        self::assertCount(1, array_filter($f->ops(), fn ($o) => $o instanceof Rect && $o->stroke !== null));
        $root = ComputedStyle::root(Theme::default());
        self::assertSame(['min' => 20.0, 'max' => 20.0], $h->engine->intrinsic(Text::make('ab')->padding(pt(5)), $root));
        self::assertSame(['min' => 80.0, 'max' => 80.0], $h->engine->intrinsic(Text::make('ab')->width(pt(80)), $root));
    }

    public function test_unknown_named_style_reports_node_path(): void
    {
        try {
            (new LayoutHarness())->layout(\Papercut\Column::make(Text::make('a'), Text::make('b')->apply('nope')), 100);
            self::fail('Expected UnknownAttribute');
        } catch (\Papercut\Exception\UnknownAttribute $e) {
            self::assertStringContainsString('at column > text[1]', $e->getMessage());
        }
    }

    // --- Task 13 ---------------------------------------------------------

    public function test_merged_runs_are_measured_as_drawn(): void
    {
        $h = new LayoutHarness(backend: new \Papercut\Tests\Support\FakeBackend(kerning: true));
        // items: "To" 10 + " " 5 + "Avoid" 25 = 40; the merged run kerns "o A" by -1pt
        $t = Text::spans(Span::text('To Avoid'), new Span('x', Style::make(fontWeight: 700)));
        $runs = LayoutHarness::runs($h->layout($t, 200)->fragment);
        self::assertSame(['To Avoid', 'x'], array_column($runs, 'text'));
        self::assertSame([0.0, 39.0], array_column($runs, 'x'));

        $ops = $h->layout(Text::spans(new Span('To Avoid', Style::make(underline: true), href: 'https://x.test')), 200)->fragment?->ops() ?? [];
        $lines = array_values(array_filter($ops, fn ($o) => $o instanceof LineOp));
        self::assertSame(39.0, $lines[0]->x2);
        $links = array_values(array_filter($ops, fn ($o) => $o instanceof LinkOp));
        self::assertSame(39.0, $links[0]->width);
    }

    public function test_line_cache_respects_the_parent_style(): void
    {
        $h = new LayoutHarness();
        $t = Text::make('aaa');
        $root = ComputedStyle::root(Theme::default());
        $big = $root->child(Style::make(fontSize: pt(20)));
        $height = fn (ComputedStyle $parent): ?float => $h->engine->layout($t, $parent, new Constraints(100, null))->fragment?->height;
        self::assertSame([14.0, 28.0, 14.0], [$height($root), $height($big), $height($root)]);
    }

    public function test_heading_lines_fill_an_exact_height(): void
    {
        $r = (new LayoutHarness())->layout(Text::h1('a b c'), 12, 100.8);
        self::assertSame(Status::Complete, $r->status);
        self::assertSame(['a', 'b', 'c'], array_column(LayoutHarness::runs($r->fragment), 'text'));
    }

    public function test_line_boxes_are_cached_across_continuations(): void
    {
        $h = new LayoutHarness();
        $t = Text::make('a b c d e f g h i j');
        $first = $h->layout($t, 5, 28);
        self::assertSame(Status::Partial, $first->status);
        $font = $h->engine->context()->fonts->resolve('Inter')->inner();
        self::assertInstanceOf(\Papercut\Tests\Support\FakeFont::class, $font);
        $font->metricCalls = 0;
        $rest = $h->layout($t, 5, null, $first->token);
        self::assertSame(Status::Complete, $rest->status);
        self::assertSame(0, $font->metricCalls);
    }

    // --- Final review ---

    public function test_right_and_center_alignment_use_the_measured_merged_width(): void
    {
        $h = new LayoutHarness(backend: new \Papercut\Tests\Support\FakeBackend(kerning: true));
        // items sum to 40pt, the merged run "To Avoid" is drawn 39pt wide
        $right = LayoutHarness::runs($h->layout(Text::make('To Avoid')->right(), 100)->fragment);
        self::assertSame(61.0, $right[0]['x']);
        $center = LayoutHarness::runs($h->layout(Text::make('To Avoid')->center(), 100)->fragment);
        self::assertSame(30.5, $center[0]['x']);
    }

    /** A TextLayouter that counts intrinsic() calls. */
    private static function countingTextLayouter(): \Papercut\Layout\Layouter
    {
        return new class () implements \Papercut\Layout\Layouter {
            public int $intrinsics = 0;
            private \Papercut\Layout\TextLayouter $inner;

            public function __construct()
            {
                $this->inner = new \Papercut\Layout\TextLayouter();
            }

            public function layout(Node $node, ComputedStyle $style, Constraints $c, ?BreakToken $token, \Papercut\Layout\LayoutEngine $engine): LayoutResult
            {
                return $this->inner->layout($node, $style, $c, $token, $engine);
            }

            public function intrinsic(Node $node, ComputedStyle $style, \Papercut\Layout\LayoutEngine $engine): array
            {
                $this->intrinsics++;
                return $this->inner->intrinsic($node, $style, $engine);
            }
        };
    }

    public function test_intrinsic_is_memoized_per_node_and_parent_style(): void
    {
        $h = new LayoutHarness();
        $spy = self::countingTextLayouter();
        $h->engine->register(Text::class, $spy);
        $root = ComputedStyle::root(Theme::default());
        $text = Text::make('hello world');
        $first = $h->engine->intrinsic($text, $root);
        self::assertSame($first, $h->engine->intrinsic($text, $root));
        self::assertSame(1, $spy->intrinsics); // @phpstan-ignore property.notFound

        $big = $root->child(new Style(fontSize: pt(20)));
        self::assertSame(['min' => 50.0, 'max' => 110.0], $h->engine->intrinsic($text, $big)); // 0.5em per char
        self::assertSame(2, $spy->intrinsics);
        self::assertSame(['min' => 10.0, 'max' => 10.0], $h->engine->intrinsic(Text::make('hi'), $root));
        self::assertSame(3, $spy->intrinsics);
    }

    public function test_intrinsic_memo_does_not_cross_engines(): void
    {
        $text = Text::make('hello');
        $root = ComputedStyle::root(Theme::default());
        foreach ([new LayoutHarness(), new LayoutHarness()] as $h) {
            $spy = self::countingTextLayouter();
            $h->engine->register(Text::class, $spy);
            $h->engine->intrinsic($text, $root);
            self::assertSame(1, $spy->intrinsics); // @phpstan-ignore property.notFound
        }
    }
}
