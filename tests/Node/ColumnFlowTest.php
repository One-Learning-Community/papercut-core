<?php

declare(strict_types=1);

namespace Papercut\Tests\Node;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Papercut\{ColumnBreak, ColumnFlow, View};
use Papercut\Exception\InvalidValue;
use Papercut\Value\{Color, ColumnSpec};

use function Papercut\pt;

final class ColumnFlowTest extends TestCase
{
    private static function spec(View $v): ColumnSpec
    {
        $spec = $v->columnSpec();
        self::assertNotNull($spec);
        return $spec;
    }

    #[Test]
    public function make_gives_two_equal_columns_with_a_36pt_gap(): void
    {
        $spec = self::spec(ColumnFlow::make('a'));
        self::assertSame(2, $spec->count);
        self::assertSame([36.0], $spec->gaps);
        self::assertNull($spec->rule);
        self::assertEquals([['x' => 0.0, 'width' => 82.0], ['x' => 118.0, 'width' => 82.0]], $spec->columns(200.0));
    }

    #[Test]
    public function shares_split_the_space_left_after_gaps(): void
    {
        $spec = self::spec(ColumnFlow::make('a')->columnWidths(2, 1)->columnGap(pt(20)));
        self::assertEquals([['x' => 0.0, 'width' => 120.0], ['x' => 140.0, 'width' => 60.0]], $spec->columns(200.0));
    }

    #[Test]
    public function one_gap_per_space_between_columns_is_accepted(): void
    {
        $spec = self::spec(ColumnFlow::make('a')->columns(3)->columnGap(pt(10), pt(20)));
        self::assertSame([10.0, 20.0], $spec->gaps);
        $spec = self::spec(ColumnFlow::make('a')->columns(3)->columnGap(pt(8)));
        self::assertSame([8.0, 8.0], $spec->gaps);
    }

    #[Test]
    public function wrong_gap_count_is_rejected(): void
    {
        $this->expectException(InvalidValue::class);
        ColumnFlow::make('a')->columns(3)->columnGap(pt(1), pt(2), pt(3), pt(4));
    }

    #[Test]
    public function zero_columns_and_non_positive_shares_are_rejected(): void
    {
        foreach ([
            fn() => ColumnFlow::make('a')->columns(0),
            fn() => ColumnFlow::make('a')->columnWidths(),
            fn() => ColumnFlow::make('a')->columnWidths(1, 0),
            fn() => ColumnFlow::make('a')->columnWidths(1, -2),
        ] as $call) {
            try {
                $call();
                self::fail('Expected InvalidValue.');
            } catch (InvalidValue) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function rule_defaults_to_half_a_point_black(): void
    {
        $rule = self::spec(ColumnFlow::make('a')->columnRule())->rule;
        self::assertNotNull($rule);
        self::assertSame(0.5, $rule->width);
        self::assertEquals(Color::black(), $rule->color);
    }

    #[Test]
    public function debug_name_is_column_flow_and_break_is_column_break(): void
    {
        self::assertSame('column-flow', ColumnFlow::make('a')->debugName());
        self::assertSame('column-flow', View::make('a')->columns(2)->debugName());
        self::assertSame('view', View::make('a')->debugName());
        self::assertSame('column-break', ColumnBreak::make()->debugName());
    }

    #[Test]
    public function setters_keep_children_and_other_style(): void
    {
        $view = ColumnFlow::make('a')->margin(pt(4))->columns(3);
        self::assertCount(1, $view->children());
        self::assertSame(3, self::spec($view)->count);
        self::assertSame(4.0, $view->style()->margin?->top->points);
    }

    #[Test]
    public function a_plain_view_becomes_a_flow_and_the_original_is_untouched(): void
    {
        $plain = View::make('a');
        $flow = $plain->columnGap(pt(12));
        self::assertNull($plain->columnSpec());
        self::assertSame([12.0], self::spec($flow)->gaps);
    }

    #[Test]
    public function gaps_given_before_the_count_are_checked_once_it_is_known(): void
    {
        self::assertSame([10.0, 20.0], self::spec(View::make('a')->columnGap(pt(10), pt(20))->columns(3))->gaps);
        self::assertSame([10.0, 20.0], self::spec(ColumnFlow::make('a')->columnGap(pt(10), pt(20))->columns(3))->gaps);
        self::assertSame([10.0, 20.0], self::spec(ColumnFlow::make('a')->columnGap(pt(10), pt(20))->columnWidths(1, 2, 3))->gaps);
        $this->expectException(InvalidValue::class);
        ColumnFlow::make('a')->columnGap(pt(10), pt(20), pt(30))->columnWidths(1, 2, 3, 4)->columns(3);
    }

    #[Test]
    public function gap_error_never_says_one_gaps(): void
    {
        try {
            ColumnFlow::make('a')->columns(2)->columnGap(pt(1), pt(2));
            self::fail('Expected InvalidValue.');
        } catch (InvalidValue $e) {
            self::assertStringContainsString('expected 1 gap.', $e->getMessage());
        }
    }

    #[Test]
    public function negative_gaps_and_non_positive_rule_widths_are_rejected(): void
    {
        foreach ([
            static fn () => ColumnFlow::make('a')->columns(3)->columnGap(pt(-10)),
            static fn () => ColumnFlow::make('a')->columnGap(pt(-1)),
            static fn () => ColumnFlow::make('a')->columnRule(pt(-1)),
            static fn () => ColumnFlow::make('a')->columnRule(pt(0)),
            static fn () => new ColumnSpec(2, null, [-5.0]),
            static fn () => new ColumnSpec(2, null, [5.0], new \Papercut\Value\ColumnRule(0.0, Color::black())),
        ] as $make) {
            try {
                $make();
                self::fail('Expected InvalidValue.');
            } catch (InvalidValue $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
        self::assertSame([0.0], self::spec(ColumnFlow::make('a')->columnGap(pt(0)))->gaps);
    }

    #[Test]
    public function columns_never_start_beyond_the_width(): void
    {
        $cols = ColumnSpec::equal(4)->columns(100.0);
        foreach ($cols as $c) {
            self::assertLessThanOrEqual(100.0, $c['x'] + $c['width']);
        }
    }

    #[Test]
    public function gaps_left_pending_without_a_fitting_count_throw_when_read(): void
    {
        $flow = ColumnFlow::make('a')->columnGap(pt(10), pt(20));
        $this->expectException(InvalidValue::class);
        $flow->columnSpec();
    }

    #[Test]
    public function every_gap_count_error_is_grammatical(): void
    {
        foreach ([
            static fn () => ColumnFlow::make('a')->columns(3)->columnGap(pt(1), pt(2))->columns(2),
            static fn () => ColumnFlow::make('a')->columns(2)->columnGap(pt(1), pt(2)),
            static fn () => new ColumnSpec(2, null, [1.0, 2.0]),
            static fn () => ColumnFlow::make('a')->columnGap(pt(1), pt(2))->columnSpec(),
        ] as $make) {
            try {
                $make();
                self::fail('Expected InvalidValue.');
            } catch (InvalidValue $e) {
                self::assertStringNotContainsString('1 gaps', $e->getMessage());
                self::assertStringContainsString('expected 1 gap', $e->getMessage());
            }
        }
        try {
            new ColumnSpec(4, null, [1.0]);
            self::fail('Expected InvalidValue.');
        } catch (InvalidValue $e) {
            self::assertStringContainsString('expected 3 gaps', $e->getMessage());
        }
    }

    #[Test]
    public function invalid_gaps_throw_on_entering_the_view_even_when_it_is_only_measured(): void
    {
        $h = new \Papercut\Tests\Support\LayoutHarness();
        $this->expectException(InvalidValue::class);
        $this->expectExceptionMessage('expected 1 gap');
        $h->engine->styleOf(ColumnFlow::make('a')->columnGap(pt(1), pt(2)), \Papercut\Style\ComputedStyle::root(\Papercut\Style\Theme::default()));
    }
}
