<?php

declare(strict_types=1);

namespace Papercut\Tests\Value;

use Papercut\Exception\InvalidValue;
use Papercut\Value\Edges;
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

final class EdgesTest extends TestCase
{
    public function test_rethrown_error_keeps_inner_as_previous(): void
    {
        try {
            Edges::parse('1pt 20');
            self::fail('accepted');
        } catch (InvalidValue $e) {
            self::assertInstanceOf(InvalidValue::class, $e->getPrevious());
        }
        try {
            Edges::parse('1pt zzz');
            self::fail('accepted');
        } catch (InvalidValue $e) {
            self::assertInstanceOf(InvalidValue::class, $e->getPrevious());
            self::assertSame('length', $e->getPrevious()->kind);
        }
    }

    public function test_css_shorthand(): void
    {
        $this->assertEdges(Edges::parse('10pt'), 10, 10, 10, 10);
        $this->assertEdges(Edges::parse('10pt 20pt'), 10, 20, 10, 20);
        $this->assertEdges(Edges::parse('10pt 20pt 30pt'), 10, 20, 30, 20);
        $this->assertEdges(Edges::parse('10pt 20pt 30pt 40pt'), 10, 20, 30, 40);
        $this->assertEdges(Edges::parse('0'), 0, 0, 0, 0);
        $this->assertEdges(Edges::of(pt(1), pt(2)), 1, 2, 1, 2);
        $this->assertEdges(Edges::all(pt(5)), 5, 5, 5, 5);
        $this->assertEdges(Edges::zero(), 0, 0, 0, 0);
    }

    public function test_sums(): void
    {
        $e = Edges::parse('1pt 2pt 3pt 4pt');
        self::assertSame(6.0, $e->horizontal());
        self::assertSame(4.0, $e->vertical());
    }

    public function test_too_many_values(): void
    {
        $this->expectException(InvalidValue::class);
        Edges::parse('1pt 2pt 3pt 4pt 5pt');
    }

    public function test_split_unit_suggestion(): void
    {
        try {
            Edges::parse('20 mm 10mm');
            self::fail('Expected InvalidValue');
        } catch (InvalidValue $e) {
            self::assertSame('edges', $e->kind);
            self::assertSame('20mm 10mm', $e->suggestion);
        }
    }

    public function test_empty_is_invalid(): void
    {
        try {
            Edges::parse('');
            self::fail('Expected InvalidValue');
        } catch (InvalidValue $e) {
            self::assertSame('edges', $e->kind);
        }
    }

    private function assertEdges(Edges $e, float $t, float $r, float $b, float $l): void
    {
        self::assertEqualsWithDelta([$t, $r, $b, $l], [$e->top->points, $e->right->points, $e->bottom->points, $e->left->points], 1e-9);
    }
}
