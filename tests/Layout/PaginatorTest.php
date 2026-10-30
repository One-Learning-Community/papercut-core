<?php

declare(strict_types=1);

namespace Papercut\Tests\Layout;

use Papercut\{Document, Image, ListItem, ListNode, OverflowMode, Page, PageNumber, Text};
use Papercut\Exception\LayoutOverflow;
use Papercut\Layout\{LayoutContext, LayoutEngine, Paginator};
use Papercut\Paint\Painter;
use Papercut\Paint\TextRun;
use Papercut\Style\Theme;
use Papercut\Tests\Support\FakeBackend;
use Papercut\Text\FontRegistry;
use PHPUnit\Framework\TestCase;
use Papercut\Layout\{BreakToken, Constraints, Fragment, LayoutResult, Layouter};
use Papercut\Node;
use Papercut\Paint\{Clip, DrawImage};
use Papercut\Style\{ComputedStyle, Style};

use function Papercut\pt;

final class PaginatorTest extends TestCase
{
    /** @var list<string> */
    private array $warnings = [];

    /** @return list<\Papercut\Paint\PageDisplayList> */
    private function paginate(Document $doc, OverflowMode $mode = OverflowMode::Throw, ?\Closure $setup = null, ?\Papercut\Backend\Backend $backend = null): array
    {
        $b = $backend ?? new FakeBackend();
        $ctx = new LayoutContext(FontRegistry::withDefaults($b), Theme::default(), $b, $mode, function (string $w): void { $this->warnings[] = $w; });
        $engine = new LayoutEngine($ctx);
        if ($setup !== null) {
            $setup($engine);
        }
        return Painter::paint((new Paginator($engine, $ctx))->paginate($doc));
    }

    private static function stuck(LayoutEngine $engine): void
    {
        // Always Partial with the same token: never makes progress.
        $engine->register(StuckNode::class, new class implements Layouter {
            public function layout(Node $node, ComputedStyle $style, Constraints $c, ?BreakToken $token, LayoutEngine $engine): LayoutResult
            {
                return LayoutResult::partial(new Fragment($c->width, 10.0), new BreakToken(index: 0));
            }

            public function intrinsic(Node $node, ComputedStyle $style, LayoutEngine $engine): array
            {
                return ['min' => 0.0, 'max' => 0.0];
            }
        });
    }

    /** @return list<string> */
    private function texts(\Papercut\Paint\PageDisplayList $p): array
    {
        return array_values(array_map(fn (TextRun $r) => $r->text, array_filter($p->ops, fn ($o) => $o instanceof TextRun)));
    }

    private function small(): Page
    {
        // 200x100 page, 10pt margins -> body 180x80 minus header/footer
        return Page::size(pt(200), pt(100))->margin(pt(10))->headerGap(pt(0))->footerGap(pt(0));
    }

    public function test_flows_body_across_pages_with_header_footer_and_page_numbers(): void
    {
        $lines = array_map(fn (int $i) => Text::make("L$i"), range(1, 6));
        $doc = Document::make($this->small()->header('Head')->footer(PageNumber::make('{page}/{total}'))->body(...$lines));
        $pages = $this->paginate($doc);
        // body height = 80 - 14 - 14 = 52 => 3 lines (42) per page
        self::assertCount(2, $pages);
        self::assertSame(['Head', 'L1', 'L2', 'L3', '1/2'], $this->texts($pages[0]));
        self::assertSame(['Head', 'L4', 'L5', 'L6', '2/2'], $this->texts($pages[1]));
    }

    public function test_each_page_template_starts_new_page(): void
    {
        $pages = $this->paginate(Document::make($this->small()->body('A'), $this->small()->body('B')));
        self::assertCount(2, $pages);
    }

    public function test_overflow_throws_with_path(): void
    {
        $this->expectException(LayoutOverflow::class);
        $this->expectExceptionMessageMatches('/image/');
        $this->paginate(Document::make($this->small()->body(Image::bytes('x')->height(pt(500)))));
    }

    public function test_image_taller_than_the_body_moves_to_a_new_page_and_shrinks(): void
    {
        // 400pt wide => 180x90 after the width limit, taller than the 80pt body
        $pages = $this->paginate(Document::make($this->small()->body('A', Image::bytes('x')->width(pt(400)))));
        self::assertCount(2, $pages);
        self::assertSame(['A'], $this->texts($pages[0]));
        $images = array_values(array_filter($pages[1]->ops, fn ($o) => $o instanceof DrawImage));
        self::assertCount(1, $images);
        self::assertEqualsWithDelta([10.0, 10.0, 160.0, 80.0], [$images[0]->x, $images[0]->y, $images[0]->width, $images[0]->height], 1e-9);
        self::assertSame([], $this->warnings);
    }

    public function test_overflow_path_starts_at_page(): void
    {
        try {
            $this->paginate(Document::make($this->small()->body('A', Image::bytes('x')->height(pt(500)))));
            self::fail('expected LayoutOverflow');
        } catch (LayoutOverflow $e) {
            self::assertSame('page > body > image[1]', $e->path);
        }
        try {
            $this->paginate(Document::make($this->small()->body('A'), $this->small()->body(Image::bytes('x')->height(pt(500)))));
            self::fail('expected LayoutOverflow');
        } catch (LayoutOverflow $e) {
            self::assertSame('page[1] > body > image[0]', $e->path);
        }
    }

    public function test_clip_mode_clips_only_the_overflowing_node(): void
    {
        $lines = array_map(fn (int $i) => Text::make("L$i"), range(1, 20));
        $pages = $this->paginate(Document::make($this->small()->body('A', Image::bytes('x')->height(pt(500)), ...$lines)), OverflowMode::Clip);
        // page 1: A; page 2: the image clipped to the 180x80 body; then 5 lines (70pt) per page
        self::assertCount(6, $pages);
        self::assertSame(['A'], $this->texts($pages[0]));
        self::assertSame([], $this->texts($pages[1]));
        $clips = array_values(array_filter($pages[1]->ops, fn ($o) => $o instanceof Clip));
        self::assertCount(1, $clips);
        self::assertEqualsWithDelta([10.0, 10.0, 180.0, 80.0], [$clips[0]->x, $clips[0]->y, $clips[0]->width, $clips[0]->height], 1e-9);
        $rest = array_merge(...array_map(fn ($p) => $this->texts($p), array_slice($pages, 2)));
        self::assertSame(array_map(fn (int $i) => "L$i", range(1, 20)), $rest);
        self::assertSame(['Content clipped: "page > body > image[1]" is taller than the available space.'], $this->warnings);
    }

    public function test_no_progress_is_overflow(): void
    {
        try {
            $this->paginate(Document::make($this->small()->body(new StuckNode())), OverflowMode::Throw, self::stuck(...));
            self::fail('expected LayoutOverflow');
        } catch (LayoutOverflow $e) {
            self::assertSame('page > body', $e->path);
        }
    }

    public function test_no_progress_in_clip_mode_drops_the_rest(): void
    {
        $pages = $this->paginate(Document::make($this->small()->body(new StuckNode())), OverflowMode::Clip, self::stuck(...));
        self::assertCount(2, $pages);
        self::assertCount(1, $this->warnings);
        self::assertStringContainsString('page > body', $this->warnings[0]);
        self::assertStringContainsString('dropped', $this->warnings[0]);
    }

    public function test_overflow_clips_in_clip_mode(): void
    {
        $pages = $this->paginate(Document::make($this->small()->body(Image::bytes('x')->height(pt(500)))), OverflowMode::Clip);
        self::assertCount(1, $pages);
        self::assertNotEmpty($this->warnings);
    }

    public function test_header_footer_too_big(): void
    {
        $this->expectException(LayoutOverflow::class);
        $tall = Text::make("a\nb")->size(pt(40));
        $this->paginate(Document::make($this->small()->header($tall)->footer($tall)->body('x')));
    }

    public function test_background_paints_first_on_every_page(): void
    {
        $lines = array_map(fn (int $i) => Text::make("L$i"), range(1, 12));
        $pages = $this->paginate(Document::make($this->small()->background('BG')->header('Head')->body(...$lines)));
        // body height = 80 - 14 = 66 => 4 lines (56) per page
        self::assertCount(3, $pages);
        foreach ($pages as $p) {
            self::assertSame(['BG', 'Head'], array_slice($this->texts($p), 0, 2));
        }
        // the background is laid out at the full page, not inside the margins
        $first = $pages[0]->ops[0];
        self::assertInstanceOf(TextRun::class, $first);
        self::assertEqualsWithDelta(0.0, $first->x, 1e-9);
    }

    public function test_page_number_intrinsic_width_uses_999(): void
    {
        $h = new \Papercut\Tests\Support\LayoutHarness();
        $size = $h->engine->intrinsic(PageNumber::make('{page}/{total}'), \Papercut\Style\ComputedStyle::root(Theme::default()));
        // "999/999" = 7 glyphs x 5pt (FakeFont, 10pt)
        self::assertEqualsWithDelta(['min' => 35.0, 'max' => 35.0], $size, 1e-9);
    }

    public function test_page_size_carried_to_display_list(): void
    {
        $pages = $this->paginate(Document::make(Page::a4()->body('x')));
        self::assertEqualsWithDelta([595.28, 841.89], [$pages[0]->width, $pages[0]->height], 0.01);
    }

    // --- Final review ---

    public function test_clip_mode_records_the_failure_path_when_clipping_fails(): void
    {
        $h = new \Papercut\Tests\Support\LayoutHarness(overflow: OverflowMode::Clip);
        $h->engine->register(StuckNode::class, new class implements Layouter {
            public function layout(Node $node, ComputedStyle $style, Constraints $c, ?BreakToken $token, LayoutEngine $engine): LayoutResult
            {
                return LayoutResult::doesNotFit();
            }

            public function intrinsic(Node $node, ComputedStyle $style, LayoutEngine $engine): array
            {
                return ['min' => 0.0, 'max' => 0.0];
            }
        });
        self::assertSame(\Papercut\Layout\Status::DoesNotFit, $h->layout(new StuckNode(), 100, 50)->status);
        self::assertSame('stuck', $h->engine->context()->lastFailurePath);
    }

    public function test_long_list_item_shows_its_marker_on_the_first_page_only(): void
    {
        $lines = array_map(fn (int $i) => Text::make("L$i"), range(1, 20));
        $pages = $this->paginate(Document::make($this->small()->body(ListNode::ordered(ListItem::make(...$lines)))));
        self::assertGreaterThan(1, count($pages));
        self::assertContains('1.', $this->texts($pages[0]));
        self::assertContains('L1', $this->texts($pages[0]));
        foreach (array_slice($pages, 1) as $p) {
            self::assertNotContains('1.', $this->texts($p));
        }
        self::assertContains('L20', $this->texts($pages[count($pages) - 1]));
    }

    public function test_item_that_moves_to_the_next_page_takes_its_marker(): void
    {
        // 80pt body holds five 14pt lines: four fillers and item "a" fill page 1
        $fill = array_map(fn (int $i) => Text::make("F$i"), range(1, 4));
        $pages = $this->paginate(Document::make($this->small()->body(...$fill, ...[ListNode::ordered('a', 'b')])));
        self::assertCount(2, $pages);
        self::assertSame(['F1', 'F2', 'F3', 'F4', '1.', 'a'], $this->texts($pages[0]));
        self::assertSame(['2.', 'b'], $this->texts($pages[1]));
    }

    public function test_header_and_footer_without_room_are_clipped_in_clip_mode(): void
    {
        $tall = Text::make("a\nb")->size(pt(40));
        $pages = $this->paginate(Document::make($this->small()->header($tall)->footer($tall)->body('x')), OverflowMode::Clip);
        self::assertCount(1, $pages);
        self::assertContains('x', $this->texts($pages[0]));
        self::assertCount(1, $this->warnings);
        self::assertStringContainsString('header and footer leave no room', $this->warnings[0]);
    }

    public function test_a_table_too_wide_in_a_header_that_is_never_rendered_does_not_throw(): void
    {
        $wide = \Papercut\Table::make()->columns('400pt 400pt')->row('a', 'b');
        $pages = $this->paginate(Document::make($this->tall()->header('H')->evenHeader($wide)->body('one page')));
        self::assertCount(1, $pages);
        self::assertSame([], $this->warnings);
    }

    public function test_a_table_too_wide_in_a_header_throws_once_that_header_is_rendered(): void
    {
        $wide = \Papercut\Table::make()->columns('400pt 400pt')->row('a', 'b');
        $this->expectException(LayoutOverflow::class);
        $this->expectExceptionMessage('even-header');
        $this->paginate(Document::make($this->tall()->header('H')->evenHeader($wide)->body(...self::lines(40))));
    }

    public function test_a_shrunk_table_in_a_header_is_reported_only_once_that_header_is_rendered(): void
    {
        $wide = \Papercut\Table::make()->columns('auto auto')->row(str_repeat('a', 40), str_repeat('b', 40));
        $this->paginate(Document::make($this->tall()->header('H')->evenHeader($wide)->body('one page')));
        self::assertSame([], $this->warnings);
        $this->warnings = [];
        $this->paginate(Document::make($this->tall()->header('H')->evenHeader($wide)->body(...self::lines(40))));
        self::assertCount(1, $this->warnings);
        self::assertStringContainsString('approximated table columns narrower than their content', implode("\n", $this->warnings));
    }

    private function tall(): Page
    {
        // 200x300 page, 10pt margins, no gaps
        return Page::size(pt(200), pt(300))->margin(pt(10))->headerGap(pt(0))->footerGap(pt(0));
    }

    private function runOf(\Papercut\Paint\PageDisplayList $p, string $text): ?TextRun
    {
        foreach ($p->ops as $o) {
            if ($o instanceof TextRun && $o->text === $text) {
                return $o;
            }
        }
        return null;
    }

    private function xOf(\Papercut\Paint\PageDisplayList $p, string $text): float
    {
        $r = $this->runOf($p, $text);
        self::assertNotNull($r, $text);
        return $r->x;
    }

    /** @return list<Text> */
    private static function lines(int $n): array
    {
        return array_map(fn (int $i) => Text::make("L$i"), range(1, $n));
    }

    public function test_first_page_uses_the_first_header_and_later_pages_the_normal_one(): void
    {
        $doc = Document::make($this->small()->firstHeader('Letterhead')->header('Running')->body(...self::lines(8)));
        $pages = $this->paginate($doc);
        self::assertGreaterThan(1, count($pages));
        self::assertContains('Letterhead', $this->texts($pages[0]));
        self::assertNotContains('Running', $this->texts($pages[0]));
        self::assertContains('Running', $this->texts($pages[1]));
        self::assertNotContains('Letterhead', $this->texts($pages[1]));
    }

    /** @return list<\Papercut\Paint\Rect> */
    private function rects(\Papercut\Paint\PageDisplayList $p): array
    {
        return array_values(array_filter($p->ops, fn ($o) => $o instanceof \Papercut\Paint\Rect));
    }

    /** @return list<\Papercut\Node|\Papercut\Component> */
    private static function blocks(int $n): array
    {
        return array_map(fn (): \Papercut\Node => \Papercut\View::make()->height(pt(5))->background('#000'), range(1, $n));
    }

    public function test_tall_first_header_shortens_only_the_first_body(): void
    {
        $first = \Papercut\Column::make(Text::make('Letterhead'))->height(pt(100));
        $normal = \Papercut\Column::make(Text::make('Running'))->height(pt(10));
        $doc = Document::make($this->tall()->headerGap(pt(5))->firstHeader($first)->header($normal)->body(...self::blocks(100)));
        $pages = $this->paginate($doc);
        $a = $this->rects($pages[0]);
        $b = $this->rects($pages[1]);
        // body y = top margin 10 + header + gap 5
        self::assertEqualsWithDelta(115.0, $a[0]->y, 1e-9);
        self::assertEqualsWithDelta(25.0, $b[0]->y, 1e-9);
        // 175pt and 265pt of body at 5pt a block: page 2 holds exactly 90pt more
        self::assertCount(35, $a);
        self::assertCount(53, $b);
    }

    public function test_set_empty_variant_gives_the_body_the_space(): void
    {
        $with = $this->paginate(Document::make($this->small()->header('Running')->body('X')));
        $without = $this->paginate(Document::make($this->small()->header('Running')->firstHeader()->body('X')));
        $a = $this->runOf($with[0], 'X');
        $b = $this->runOf($without[0], 'X');
        self::assertNotNull($a);
        self::assertNotNull($b);
        self::assertGreaterThan(0.0, $a->baseline - $b->baseline);
        $bare = $this->paginate(Document::make($this->small()->firstHeader()->body(...self::blocks(1))));
        self::assertEqualsWithDelta(10.0, $this->rects($bare[0])[0]->y, 1e-9, 'the body sits at the top margin');
        self::assertNotContains('Running', $this->texts($without[0]));
    }

    public function test_even_pages_use_the_even_variants(): void
    {
        $doc = Document::make($this->small()->header('H-odd')->footer('F-odd')->evenHeader('H-even')->evenFooter('F-even')->body(...self::lines(12)));
        $pages = $this->paginate($doc);
        self::assertCount(4, $pages);
        foreach ([0, 2] as $i) {
            self::assertContains('H-odd', $this->texts($pages[$i]));
            self::assertContains('F-odd', $this->texts($pages[$i]));
        }
        foreach ([1, 3] as $i) {
            self::assertContains('H-even', $this->texts($pages[$i]));
            self::assertContains('F-even', $this->texts($pages[$i]));
            self::assertNotContains('H-odd', $this->texts($pages[$i]));
        }
    }

    public function test_a_later_run_has_its_own_first_page(): void
    {
        $one = $this->small()->firstHeader('First-one')->header('Run-one')->body('A');
        $two = $this->small()->firstHeader('First-two')->header('Run-two')->body('B');
        $three = $this->small()->header('Run-three')->body('C');
        $pages = $this->paginate(Document::make($one, $two, $three));
        self::assertSame(['First-one', 'A'], $this->texts($pages[0]));
        self::assertSame(['First-two', 'B'], $this->texts($pages[1]));
        self::assertSame(['Run-three', 'C'], $this->texts($pages[2]));
    }

    public function test_later_run_on_an_even_page_takes_first_header_and_even_footer(): void
    {
        $one = $this->small()->header('Run-one')->body('A');
        $two = $this->small()->header('Run-two')->footer('Foot-odd')->firstHeader('First-two')->evenFooter('Foot-even')->body('B');
        $pages = $this->paginate(Document::make($one, $two));
        self::assertCount(2, $pages);
        self::assertContains('First-two', $this->texts($pages[1]));
        self::assertContains('Foot-even', $this->texts($pages[1]));
        self::assertNotContains('Run-two', $this->texts($pages[1]));
        self::assertNotContains('Foot-odd', $this->texts($pages[1]));
    }

    public function test_mirroring_moves_header_body_and_footer_but_not_layers(): void
    {
        $bytes = Document::make($this->small()->body('T'))->toPdf()->bytes();
        $page = Page::template(\Papercut\PdfPage::bytes($bytes))->margin(\Papercut\Value\Edges::of(pt(10), pt(10), pt(10), pt(30)))->mirrorMargins()
            ->background('BG')->overlay(\Papercut\Place::at(pt(50), pt(50), 'Over'))
            ->header('Head')->footer('Foot')->body(...self::lines(8));
        $pages = $this->paginate(Document::make($page), backend: \Papercut\Backend\DefaultBackend::make(false));
        self::assertGreaterThan(1, count($pages));
        foreach (['Head', 'L1', 'Foot'] as $t) {
            self::assertEqualsWithDelta(30.0, $this->xOf($pages[0], $t), 1e-9, $t);
        }
        self::assertEqualsWithDelta(10.0, $this->xOf($pages[1], 'Head'), 1e-9);
        self::assertEqualsWithDelta(10.0, $this->xOf($pages[1], (string) current(array_filter($this->texts($pages[1]), fn ($t) => $t[0] === 'L'))), 1e-9, 'page 2 body');
        $drawn = fn ($p) => array_values(array_filter($p->ops, fn ($o) => $o instanceof \Papercut\Paint\DrawPdfPage));
        self::assertCount(1, $drawn($pages[0]));
        self::assertCount(1, $drawn($pages[1]));
        self::assertEquals([$drawn($pages[0])[0]->x, $drawn($pages[0])[0]->y], [$drawn($pages[1])[0]->x, $drawn($pages[1])[0]->y]);
        self::assertEqualsWithDelta(10.0, $this->xOf($pages[1], 'Foot'), 1e-9);
        foreach (['BG', 'Over'] as $t) {
            $a = $this->runOf($pages[0], $t);
            $b = $this->runOf($pages[1], $t);
            self::assertNotNull($a);
            self::assertNotNull($b);
            self::assertEqualsWithDelta($a->x, $b->x, 1e-9, $t);
            self::assertEqualsWithDelta($a->baseline, $b->baseline, 1e-9, $t);
        }
    }

    public function test_table_flows_across_pages_of_different_heights(): void
    {
        $tall = \Papercut\Column::make(Text::make('Letterhead'))->height(pt(120));
        $table = \Papercut\Table::make()->head('Col');
        for ($i = 1; $i <= 40; $i++) {
            $table = $table->row("Row$i");
        }
        $pages = $this->paginate(Document::make($this->tall()->firstHeader($tall)->header('Running')->body($table)));
        self::assertGreaterThan(2, count($pages));
        $all = array_merge(...array_map(fn ($p) => $this->texts($p), $pages));
        for ($i = 1; $i <= 40; $i++) {
            self::assertSame(1, count(array_keys($all, "Row$i", true)), "Row$i");
        }
        foreach ($pages as $p) {
            self::assertContains('Col', $this->texts($p));
        }
        self::assertContains('Letterhead', $this->texts($pages[0]));
        $rows = fn ($p) => count(array_filter($this->texts($p), fn ($t) => str_starts_with($t, 'Row')));
        self::assertLessThan($rows($pages[1]), $rows($pages[0]));
    }

    public function test_block_too_tall_for_the_first_page_moves_to_the_second(): void
    {
        $tall = \Papercut\Column::make(Text::make('Letterhead'))->height(pt(100));
        $block = \Papercut\Column::make(Text::make('Block'))->height(pt(200));
        $doc = Document::make($this->tall()->firstHeader($tall)->header('Running')->footer('Foot')->body($block));
        $pages = $this->paginate($doc);
        self::assertCount(2, $pages);
        self::assertSame(['Letterhead', 'Foot'], $this->texts($pages[0]));
        self::assertSame(['Running', 'Block', 'Foot'], $this->texts($pages[1]));
        self::assertSame([], $this->warnings);

        // Clip mode lays out the same: the block moves rather than being clipped away.
        $this->warnings = [];
        $pages = $this->paginate($doc, OverflowMode::Clip);
        self::assertCount(2, $pages);
        self::assertSame(['Letterhead', 'Foot'], $this->texts($pages[0]));
        self::assertSame(['Running', 'Block', 'Foot'], $this->texts($pages[1]));
        self::assertSame([], $this->warnings);
    }

    public function test_no_room_check_runs_for_each_combination(): void
    {
        $huge = \Papercut\Column::make(Text::make('Letterhead'))->height(pt(400));
        $page = $this->tall()->firstHeader($huge)->header('Running')->body('X');
        try {
            $this->paginate(Document::make($page));
            self::fail('expected overflow');
        } catch (LayoutOverflow $e) {
            self::assertStringContainsString('(first page)', $e->getMessage());
        }
        $pages = $this->paginate(Document::make($page), OverflowMode::Clip);
        self::assertCount(1, $pages);
        self::assertCount(1, $this->warnings);
        self::assertStringContainsString('(first page)', $this->warnings[0]);
        $this->warnings = [];
        $pages = $this->paginate(Document::make($this->tall()->firstHeader($huge)->header('Running')->body(...self::lines(40))), OverflowMode::Clip);
        self::assertGreaterThan(1, count($pages));
        self::assertContains('Running', $this->texts($pages[1]));
        self::assertCount(1, $this->warnings, 'page 2 with the fitting normal header adds no warning');
        self::assertStringContainsString('(first page)', implode(' ', $this->warnings));

        $this->warnings = [];
        $this->paginate(Document::make($this->tall()->evenHeader($huge)->body(...self::lines(40))), OverflowMode::Clip);
        self::assertCount(1, $this->warnings);
        self::assertStringContainsString('(even page)', implode(' ', $this->warnings));
    }

    public function test_look_ahead_warns_only_for_pages_that_are_rendered(): void
    {
        $huge = \Papercut\Column::make(Text::make('Even'))->height(pt(400));
        $tall = \Papercut\Column::make(Text::make('Letterhead'))->height(pt(100));
        $pages = $this->paginate(Document::make($this->tall()->firstHeader($tall)->evenHeader($huge)->body('X')), OverflowMode::Clip);
        self::assertCount(1, $pages);
        self::assertSame([], $this->warnings, 'page 2 is never rendered, so its band says nothing');
    }

    public function test_block_that_fits_on_no_page_does_not_leave_an_empty_first_page(): void
    {
        $tall = \Papercut\Column::make(Text::make('Letterhead'))->height(pt(100));
        $block = \Papercut\Column::make(Text::make('Block'))->height(pt(500));
        $doc = Document::make($this->tall()->firstHeader($tall)->header('Running')->body($block));
        try {
            $this->paginate($doc);
            self::fail('expected overflow');
        } catch (LayoutOverflow $e) {
            self::assertStringContainsString('taller than the page body', $e->getMessage());
        }
        $pages = $this->paginate($doc, OverflowMode::Clip);
        self::assertCount(1, $pages);
        self::assertContains('Letterhead', $this->texts($pages[0]));
        self::assertCount(1, $this->warnings);
        self::assertStringContainsString('page > body > column[0]', $this->warnings[0], 'clipped in place on page 1, as without a short page');
    }

    public function test_block_moves_past_a_short_even_page(): void
    {
        $even = \Papercut\Column::make(Text::make('Even'))->height(pt(100));
        $a = \Papercut\Column::make(Text::make('A'))->height(pt(150));
        $b = \Papercut\Column::make(Text::make('B'))->height(pt(200));
        $doc = Document::make($this->tall()->header('Odd')->evenHeader($even)->body($a, $b));
        foreach ([OverflowMode::Throw, OverflowMode::Clip] as $mode) {
            $this->warnings = [];
            $pages = $this->paginate($doc, $mode);
            self::assertCount(3, $pages, $mode->name);
            self::assertSame(['Odd', 'A'], $this->texts($pages[0]));
            self::assertSame(['Even'], $this->texts($pages[1]));
            self::assertSame(['Odd', 'B'], $this->texts($pages[2]));
            self::assertSame([], $this->warnings);
        }
    }

    public function test_block_moves_two_pages_on_when_only_the_page_after_next_can_hold_it(): void
    {
        // Page body 280pt: the first page keeps 100pt, even pages 150pt, odd pages 280pt.
        $first = \Papercut\Column::make(Text::make('First'))->height(pt(180));
        $even = \Papercut\Column::make(Text::make('Even'))->height(pt(130));
        $block = \Papercut\Column::make(Text::make('Block'))->height(pt(190));
        $doc = Document::make($this->tall()->firstHeader($first)->evenHeader($even)->header('Odd')->body($block));
        foreach ([OverflowMode::Throw, OverflowMode::Clip] as $mode) {
            $this->warnings = [];
            $pages = $this->paginate($doc, $mode);
            self::assertCount(3, $pages, $mode->name);
            self::assertSame(['First'], $this->texts($pages[0]));
            self::assertSame(['Even'], $this->texts($pages[1]));
            self::assertSame(['Odd', 'Block'], $this->texts($pages[2]));
            self::assertSame([], $this->warnings);
        }
    }

    public function test_layout_warnings_are_not_repeated_when_a_block_is_tried_again(): void
    {
        $tall = \Papercut\Column::make(Text::make('Letterhead'))->height(pt(100));
        $table = \Papercut\Table::make()->columns('300pt 300pt 300pt');
        for ($i = 0; $i < 60; $i++) {
            $table = $table->row('a', 'b');
        }
        $doc = Document::make($this->tall()->firstHeader($tall)->header('Running')->body($table));
        // Clip mode: in Throw mode hand-set columns wider than the page throw instead of warning
        $this->paginate($doc, OverflowMode::Clip);
        self::assertSame(array_values(array_unique($this->warnings)), $this->warnings);
        self::assertCount(2, $this->warnings);
    }

    public function test_caption_over_a_table_whose_first_row_is_taller_than_a_page_does_not_split_the_row_in_place(): void
    {
        // Accepted: splitting the row in place would truncate fixed-height boxes inside it.
        // body 180 x 52: three lines a page; the table's first row is eight lines
        $tall = \Papercut\View::make(...array_map(static fn (int $i): Text => Text::make("T$i"), range(1, 8)));
        $table = \Papercut\Table::make()->columns('1fr')->row($tall);
        $doc = Document::make($this->small()->body(Text::make('Intro'), Text::make('Caption')->keepWithNext(), $table));
        $pages = array_map(fn ($p) => $this->texts($p), $this->paginate($doc, OverflowMode::Throw));
        // The caption is left alone on its own page; the first row starts on the next one and splits there.
        self::assertSame([['Intro'], ['Caption'], ['T1', 'T2', 'T3', 'T4', 'T5'], ['T6', 'T7', 'T8']], $pages);
    }

    public function test_heading_moves_with_a_keep_together_block_that_jumps_a_page(): void
    {
        // body 180 x 110: every heading level fits after the four intro lines, the four-line box does not
        $box = \Papercut\View::make(...array_map(static fn (int $i): Text => Text::make("B$i"), range(1, 4)))->keepTogether();
        $intro = array_map(static fn (int $i): Text => Text::make("I$i"), range(1, 4));
        foreach (['h1', 'h2', 'h3', 'h4'] as $level) {
            $page = Page::size(pt(200), pt(130))->margin(pt(10))->headerGap(pt(0))->footerGap(pt(0));
            $doc = Document::make($page->body(...[...$intro, Text::$level('Heading'), $box]));
            $pages = array_map(fn ($p) => $this->texts($p), $this->paginate($doc));
            self::assertSame([['I1', 'I2', 'I3', 'I4'], ['Heading', 'B1', 'B2', 'B3', 'B4']], $pages, $level);
        }
    }

    public function test_a_heading_can_opt_out_of_keeping_with_next(): void
    {
        $box = \Papercut\View::make(...array_map(static fn (int $i): Text => Text::make("B$i"), range(1, 4)))->keepTogether();
        $doc = Document::make($this->small()->body(Text::make('Intro'), Text::make('Intro 2'), Text::h4('Heading')->keepWithNext(false), $box));
        $pages = array_map(fn ($p) => $this->texts($p), $this->paginate($doc));
        self::assertSame([['Intro', 'Intro 2', 'Heading'], ['B1', 'B2', 'B3', 'B4']], $pages);
    }
}

final class StuckNode implements Node
{
    public function debugName(): string
    {
        return 'stuck';
    }

    public function style(): Style
    {
        return new Style();
    }
}
