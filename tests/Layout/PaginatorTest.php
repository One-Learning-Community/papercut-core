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
    private function paginate(Document $doc, OverflowMode $mode = OverflowMode::Throw, ?\Closure $setup = null): array
    {
        $b = new FakeBackend();
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
