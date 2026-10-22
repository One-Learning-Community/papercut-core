<?php

declare(strict_types=1);

namespace Papercut\Tests\Layout;

use Papercut\{PageNumber, Spacer, Text, View};
use Papercut\Layout\{BoxGeometry, BreakToken, Constraints, ExactWidth, Fragment, LayoutResult, ParallelSplit, Status};
use Papercut\Style\{ComputedStyle, Justify, Style, Theme};
use Papercut\Tests\Support\LayoutHarness;
use PHPUnit\Framework\TestCase;

use function Papercut\{percent, pt};

final class LayoutHelpersTest extends TestCase
{
    private static function style(Style $s): ComputedStyle
    {
        return ComputedStyle::root(Theme::default())->child($s);
    }

    public function test_justify_distribution(): void
    {
        self::assertSame([0.0, 0.0], BoxGeometry::justify(Justify::Start, 30, 3));
        self::assertSame([30.0, 0.0], BoxGeometry::justify(Justify::End, 30, 3));
        self::assertSame([15.0, 0.0], BoxGeometry::justify(Justify::Center, 30, 3));
        self::assertSame([0.0, 15.0], BoxGeometry::justify(Justify::SpaceBetween, 30, 3));
        self::assertSame([0.0, 0.0], BoxGeometry::justify(Justify::SpaceBetween, 30, 1));
        self::assertSame([5.0, 10.0], BoxGeometry::justify(Justify::SpaceAround, 30, 3));
        self::assertSame([7.5, 7.5], BoxGeometry::justify(Justify::SpaceEvenly, 30, 3));
        self::assertSame([0.0, 0.0], BoxGeometry::justify(Justify::End, 0.0, 3));
        self::assertSame([0.0, 0.0], BoxGeometry::justify(Justify::End, 30, 0));
    }

    public function test_height_resolution(): void
    {
        $c = new Constraints(100, null, true, 80.0);
        $none = self::style(new Style());
        self::assertNull(BoxGeometry::explicitHeight($none, $c));
        self::assertSame(80.0, BoxGeometry::definiteHeight($none, $c));
        self::assertNull(BoxGeometry::definiteHeight($none, new Constraints(100, null)));

        $pct = self::style(new Style(height: percent(50), maxHeight: pt(30)));
        self::assertSame(30.0, BoxGeometry::explicitHeight($pct, $c));
        self::assertSame(30.0, BoxGeometry::definiteHeight($pct, $c));
        self::assertNull(BoxGeometry::explicitHeight($pct, new Constraints(100, null)));

        $min = self::style(new Style(minHeight: percent(150)));
        self::assertSame(120.0, BoxGeometry::clampHeight($min, $c, 10.0));
        self::assertNull(BoxGeometry::clampHeight($min, $c, null));
        self::assertSame(0.0, BoxGeometry::clampHeight($none, $c, -5.0));
    }

    public function test_parallel_measure_takes_the_tallest_extent(): void
    {
        $heights = [10.0, 30.0, 20.0];
        $m = ParallelSplit::measure([0, 1, 2], 5.0, fn (int $k): Fragment => new Fragment(10, $heights[$k]), fn (int $k, Fragment $f): float => $f->height + 1);
        self::assertSame(31.0, $m['height']);
        self::assertSame([10.0, 30.0, 20.0], array_map(fn (Fragment $f) => $f->height, $m['fragments']));
        self::assertSame(50.0, ParallelSplit::measure([0], 50.0, fn (int $k): Fragment => new Fragment(10, 1), fn (int $k, Fragment $f): float => $f->height)['height']);
    }

    public function test_parallel_split_offsets_tokens_and_truncates_trailing_space(): void
    {
        $slots = [['x' => 0.0, 'top' => 5.0, 'bottom' => 20.0], ['x' => 50.0, 'top' => 0.0, 'bottom' => 0.0]];
        $rooms = [];
        $layout = function (int $j, ?float $room, ?BreakToken $t) use (&$rooms): LayoutResult {
            $rooms[$j] = $room;
            return $j === 0
                ? LayoutResult::complete(new Fragment(10, 10))
                : LayoutResult::partial(new Fragment(10, (float) $room), new BreakToken(index: 3));
        };
        $split = ParallelSplit::split($slots, 25.0, null, $layout);
        self::assertNotNull($split);
        self::assertSame([0 => 20.0, 1 => 25.0], $rooms);
        self::assertSame(25.0, $split['height']); // 5 + 10 + 20 truncated to 25
        self::assertNull($split['tokens'][0]);
        self::assertSame(3, $split['tokens'][1]?->index);
        self::assertSame([[0.0, 5.0], [50.0, 0.0]], array_map(fn (array $c) => [$c['x'], $c['y']], $split['cells']));
        self::assertTrue(ParallelSplit::pending($split['tokens']));

        // resume: the finished child is skipped, the other starts at y 0
        $rooms = [];
        $resumed = ParallelSplit::split($slots, null, $split['tokens'], fn (int $j, ?float $room, ?BreakToken $t): LayoutResult => LayoutResult::complete(new Fragment(10, 7)));
        self::assertNotNull($resumed);
        self::assertCount(1, $resumed['cells']);
        self::assertSame(7.0, $resumed['height']);
        self::assertFalse(ParallelSplit::pending($resumed['tokens']));

        self::assertNull(ParallelSplit::split($slots, 10.0, null, fn (int $j, ?float $room, ?BreakToken $t): LayoutResult => LayoutResult::doesNotFit()));
    }

    public function test_exact_width_pins_only_when_needed(): void
    {
        $root = ComputedStyle::root(Theme::default());
        $text = Text::make('a');
        self::assertSame($text, ExactWidth::pin($text, 80, self::style(new Style())));
        $narrow = Text::make('a')->width(pt(20));
        $pinned = ExactWidth::pin($narrow, 80, self::style($narrow->style()));
        self::assertNotSame($narrow, $pinned);
        self::assertEquals(pt(80), $pinned->style()->width);
        self::assertEquals(pt(80), $pinned->style()->maxWidth);

        self::assertTrue(ExactWidth::styleable(PageNumber::make('{page}')));
        self::assertTrue(ExactWidth::styleable(View::make()));

        $h = new LayoutHarness();
        $r = ExactWidth::layout($narrow, 80, $root, new Constraints(200, null), null, $h->engine, 0);
        self::assertSame(80.0, $r->fragment?->width);
        $s = ExactWidth::layout(Spacer::flex(), 30, $root, new Constraints(200, null), null, $h->engine, 0);
        self::assertSame(Status::Complete, $s->status);
        self::assertSame([30.0, 0.0], [$s->fragment?->width, $s->fragment?->height]);
    }
}
