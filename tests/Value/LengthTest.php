<?php

declare(strict_types=1);

namespace Papercut\Tests\Value;

use Papercut\Exception\InvalidValue;
use Papercut\Value\Length;
use Papercut\Value\Unit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Papercut\{cm, in, mm, pt, px};

final class LengthTest extends TestCase
{
    /** @return iterable<string, array{string, float}> */
    public static function validInputs(): iterable
    {
        yield 'points' => ['12pt', 12.0];
        yield 'pixels' => ['16px', 12.0];
        yield 'millimetres' => ['25.4mm', 72.0];
        yield 'centimetres' => ['2.54cm', 72.0];
        yield 'inches' => ['1in', 72.0];
        yield 'leading dot' => ['.5in', 36.0];
        yield 'negative' => ['-10pt', -10.0];
        yield 'bare zero' => ['0', 0.0];
        yield 'zero with decimals' => ['0.0', 0.0];
        yield 'surrounding whitespace' => ['  12pt ', 12.0];
    }

    #[DataProvider('validInputs')]
    public function test_parse_valid(string $input, float $points): void
    {
        self::assertEqualsWithDelta($points, Length::parse($input)->points, 1e-9);
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function invalidInputs(): iterable
    {
        yield 'space before unit' => ['20 mm', '20mm'];
        yield 'uppercase unit' => ['20MM', '20mm'];
        yield 'bare non-zero number' => ['12', '12pt'];
        yield 'unknown unit' => ['12em', null];
        yield 'empty' => ['', null];
        yield 'garbage' => ['wide', null];
        yield 'exponent' => ['1e3pt', null];
        yield 'percent is not a length' => ['50%', null];
        yield 'uppercase unit with space' => ['20 MM', '20mm'];
        yield 'bare negative number' => ['-5', '-5pt'];
        yield 'double space before unit' => ['20  mm', '20mm'];
        yield 'overflow to infinity' => ['1' . str_repeat('0', 400) . 'pt', null];
    }

    #[DataProvider('invalidInputs')]
    public function test_parse_invalid(string $input, ?string $suggestion): void
    {
        try {
            Length::parse($input);
            self::fail('Expected InvalidValue');
        } catch (InvalidValue $e) {
            self::assertSame('length', $e->kind);
            self::assertSame($input, $e->input);
            self::assertSame($suggestion, $e->suggestion);
        }
    }

    /** @return iterable<string, array{float}> */
    public static function nonFinite(): iterable
    {
        yield 'nan' => [NAN];
        yield 'inf' => [INF];
        yield '-inf' => [-INF];
    }

    #[DataProvider('nonFinite')]
    public function test_of_and_points_reject_non_finite(float $v): void
    {
        foreach ([fn () => Length::of($v, Unit::Pt), fn () => Length::points($v)] as $fn) {
            try {
                $fn();
                self::fail('Expected InvalidValue');
            } catch (InvalidValue $e) {
                self::assertSame('length', $e->kind);
                self::assertNull($e->suggestion);
            }
        }
    }

    public function test_of_rejects_unit_overflow(): void
    {
        $this->expectException(InvalidValue::class);
        Length::of(PHP_FLOAT_MAX, Unit::In);
    }

    public function test_negative_zero_prints_as_zero(): void
    {
        self::assertSame('0pt', (string) Length::parse('-0pt'));
    }

    public function test_helpers_and_units(): void
    {
        self::assertSame(12.0, pt(12)->points);
        self::assertSame(12.0, px(16)->points);
        self::assertEqualsWithDelta(72.0, mm(25.4)->points, 1e-9);
        self::assertEqualsWithDelta(72.0, cm(2.54)->points, 1e-9);
        self::assertSame(72.0, in(1)->points);
        self::assertSame(72.0, Length::of(1, Unit::In)->points);
    }

    public function test_arithmetic_and_equality(): void
    {
        self::assertSame(15.0, pt(10)->plus(pt(5))->points);
        self::assertSame(5.0, pt(10)->minus(pt(5))->points);
        self::assertSame(20.0, pt(10)->scale(2)->points);
        self::assertTrue(mm(25.4)->equals(in(1)));
        self::assertFalse(pt(1)->equals(pt(2)));
        self::assertSame(0.0, Length::zero()->points);
    }

    public function test_arithmetic_rejects_non_finite_results(): void
    {
        $big = Length::points(1e308);
        foreach ([
            static fn () => $big->plus($big),
            static fn () => Length::points(-1e308)->minus($big),
            static fn () => $big->scale(10.0),
            static fn () => Length::points(1.0)->scale(NAN),
        ] as $op) {
            try {
                $op();
                self::fail('accepted non-finite result');
            } catch (InvalidValue $e) {
                self::assertSame('length', $e->kind);
            }
        }
    }

    public function test_canonical_string(): void
    {
        self::assertSame('12pt', (string) pt(12));
        self::assertSame('56.6929pt', (string) mm(20));
        self::assertSame('0pt', (string) Length::zero());
        self::assertSame('-3.5pt', (string) pt(-3.5));
        self::assertTrue(Length::parse((string) mm(20))->equals(mm(20), 1e-3));
    }
}
