<?php

declare(strict_types=1);

namespace Papercut\Tests\Layout;

use Papercut\{ListNode, Text};
use Papercut\Layout\Fragment;
use Papercut\Paint\TextRun;
use Papercut\Tests\Support\LayoutHarness;
use PHPUnit\Framework\TestCase;

final class ListMarkerAlignmentTest extends TestCase
{
    public function test_markers_right_align_and_text_starts_together(): void
    {
        $list = ListNode::ordered(...array_map(static fn (int $i): string => "item$i", range(1, 12)));
        $res = (new LayoutHarness())->layout($list, 300);
        $runs = self::collect($res->fragment, 0.0, 0.0);

        $rights = [];
        $starts = [];
        foreach ($runs as $r) {
            if (preg_match('/^\d+\.$/', $r['text']) === 1) {
                $rights[] = $r['right'];
            } else {
                $starts[] = $r['x'];
            }
        }
        self::assertCount(12, $rights);
        self::assertCount(12, $starts);
        foreach ($rights as $right) {
            self::assertEqualsWithDelta($rights[0], $right, 0.01);
        }
        foreach ($starts as $x) {
            self::assertEqualsWithDelta($starts[0], $x, 0.01);
        }
    }

    /** @return list<array{text: string, x: float, right: float}> */
    private static function collect(?Fragment $f, float $ox, float $oy): array
    {
        if ($f === null) {
            return [];
        }
        $out = [];
        foreach ($f->ops() as $op) {
            if ($op instanceof TextRun) {
                $x = $ox + $op->x;
                $out[] = ['text' => $op->text, 'x' => $x, 'right' => $x + $op->font->advance($op->text, $op->size, $op->letterSpacing)];
            }
        }
        foreach ($f->children() as $c) {
            array_push($out, ...self::collect($c['fragment'], $ox + $c['x'], $oy + $c['y']));
        }
        return $out;
    }
}
