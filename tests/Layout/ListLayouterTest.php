<?php

declare(strict_types=1);

namespace Papercut\Tests\Layout;

use Papercut\{Image, ListItem, ListNode, Text};
use Papercut\Layout\{BreakToken, Fragment, Status};
use Papercut\Paint\TextRun;
use Papercut\Style\ListMarker;
use Papercut\Tests\Support\LayoutHarness;
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

final class ListLayouterTest extends TestCase
{
    private const array MARKERS = ['1.', '2.', '3.', '4.', '5.', '6.', '7.', '8.', '9.', '10.', 'a.', 'b.', 'i.', "\u{2022}"];

    public function test_markers_by_depth_in_a_nested_ordered_list(): void
    {
        $list = ListNode::ordered('one', ListItem::make('two', ListNode::ordered('a', ListItem::make('b', ListNode::ordered(ListItem::make('x', ListNode::ordered('deep')))))));
        $markers = array_values(array_map(
            static fn (array $r): string => $r['text'],
            array_filter($this->runs($list), static fn (array $r): bool => in_array($r['text'], self::MARKERS, true)),
        ));
        self::assertSame(['1.', '2.', 'a.', 'b.', 'i.', '1.'], $markers);
    }

    public function test_numbers_right_align_in_the_gutter(): void
    {
        $list = ListNode::ordered(...array_map(static fn (int $i): string => "item$i", range(1, 10)));
        $runs = $this->byText($this->runs($list));
        self::assertEqualsWithDelta($runs['10.']['right'], $runs['9.']['right'], 1e-6);
        $xs = array_map(static fn (int $i): float => $runs["item$i"]['x'], range(1, 10));
        self::assertCount(1, array_unique(array_map(static fn (float $x): string => sprintf('%.6f', $x), $xs)));
        self::assertGreaterThan($runs['10.']['right'], $xs[0]);
        self::assertEqualsWithDelta($runs['item10']['baseline'], $runs['10.']['baseline'], 1e-9);
    }

    public function test_nested_list_indents_by_its_own_gutter(): void
    {
        $list = ListNode::ordered(ListItem::make('d1', ListNode::ordered(ListItem::make('d2', ListNode::ordered(ListItem::make('d3', ListNode::ordered('d4')))))));
        $runs = $this->byText($this->runs($list));
        self::assertGreaterThan($runs['d1']['x'], $runs['d2']['x']);
        self::assertGreaterThan($runs['d2']['x'], $runs['d3']['x']);
        self::assertGreaterThan($runs['d3']['x'], $runs['d4']['x']);
    }

    public function test_bullets_and_none(): void
    {
        $bullets = array_column($this->runs(ListNode::unordered('a', 'b')), 'text');
        self::assertSame(["\u{2022}", 'a', "\u{2022}", 'b'], $bullets);

        $runs = $this->runs(ListNode::unordered('a', 'b')->marker(ListMarker::None));
        self::assertSame(['a', 'b'], array_column($runs, 'text'));
        self::assertEqualsWithDelta(0.0, $runs[0]['x'], 1e-9);
    }

    public function test_marker_shares_the_baseline_of_a_larger_first_line(): void
    {
        $runs = $this->byText($this->runs(ListNode::ordered(ListItem::make(Text::make('Big')->size(pt(24)), 'small'))));
        self::assertEqualsWithDelta($runs['Big']['baseline'], $runs['1.']['baseline'], 1e-9);
    }

    public function test_marker_shares_the_baseline_of_a_first_child_with_a_top_margin(): void
    {
        $runs = $this->byText($this->runs(ListNode::ordered(ListItem::make(Text::p('para')->margin(pt(12), pt(0))))));
        self::assertGreaterThan(12.0, $runs['para']['baseline']);
        self::assertEqualsWithDelta($runs['para']['baseline'], $runs['1.']['baseline'], 1e-9);
    }

    public function test_marker_of_a_larger_item_stays_in_the_gutter(): void
    {
        $runs = $this->byText($this->runs(ListNode::ordered(ListItem::make('a')->size(pt(30)), 'b')));
        self::assertGreaterThanOrEqual(0.0, $runs['1.']['x']);
        self::assertGreaterThanOrEqual(0.0, $runs['2.']['x']);
        self::assertEqualsWithDelta($runs['1.']['right'], $runs['2.']['right'], 1e-6);
        self::assertGreaterThan($runs['1.']['right'], $runs['a']['x']);
        self::assertEqualsWithDelta($runs['a']['x'], $runs['b']['x'], 1e-9);
    }

    public function test_empty_list_and_blank_item_take_no_space(): void
    {
        $res = (new LayoutHarness())->layout(ListNode::ordered(), 300);
        self::assertSame(Status::Complete, $res->status);
        self::assertNotNull($res->fragment);
        self::assertSame(0.0, $res->fragment->height);

        $res = (new LayoutHarness())->layout(ListNode::ordered(ListItem::make()), 300);
        self::assertSame(Status::Complete, $res->status);
    }

    public function test_layout_paths_name_list_items(): void
    {
        $h = new LayoutHarness();
        // resuming at item 1 on a fresh page: its too-tall image is the recorded failure
        $list = ListNode::ordered('a', ListItem::make(Image::bytes('x')->height(pt(500))));
        $res = $h->layout($list, 300, 100, new BreakToken(index: 1));
        self::assertSame(Status::DoesNotFit, $res->status);
        self::assertSame('list > item[1] > image[0]', $h->engine->context()->lastFailurePath);
    }

    /**
     * @return list<array{text: string, x: float, right: float, baseline: float}>
     */
    private function runs(ListNode $list): array
    {
        $res = (new LayoutHarness())->layout($list, 300);
        self::assertSame(Status::Complete, $res->status);
        return self::collect($res->fragment, 0.0, 0.0);
    }

    /**
     * @param list<array{text: string, x: float, right: float, baseline: float}> $runs
     * @return array<string, array{text: string, x: float, right: float, baseline: float}>
     */
    private function byText(array $runs): array
    {
        $out = [];
        foreach ($runs as $r) {
            $out[$r['text']] = $r;
        }
        return $out;
    }

    /**
     * @return list<array{text: string, x: float, right: float, baseline: float}>
     */
    private static function collect(?Fragment $f, float $ox, float $oy): array
    {
        if ($f === null) {
            return [];
        }
        $out = [];
        foreach ($f->ops() as $op) {
            if ($op instanceof TextRun) {
                $x = $ox + $op->x;
                $out[] = ['text' => $op->text, 'x' => $x, 'right' => $x + $op->font->advance($op->text, $op->size, $op->letterSpacing), 'baseline' => $oy + $op->baseline];
            }
        }
        foreach ($f->children() as $c) {
            array_push($out, ...self::collect($c['fragment'], $ox + $c['x'], $oy + $c['y']));
        }
        return $out;
    }
}
