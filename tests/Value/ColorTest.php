<?php

declare(strict_types=1);

namespace Papercut\Tests\Value;

use Papercut\Exception\InvalidValue;
use Papercut\Value\Color;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ColorTest extends TestCase
{
    /** @return iterable<string, array{string, int, int, int, float}> */
    public static function valid(): iterable
    {
        yield 'short hex' => ['#fa0', 255, 170, 0, 1.0];
        yield 'long hex' => ['#334155', 51, 65, 85, 1.0];
        yield 'uppercase hex' => ['#33AAFF', 51, 170, 255, 1.0];
        yield 'hex with alpha' => ['#33415580', 51, 65, 85, 0.502];
        yield 'rgb commas' => ['rgb(51, 65, 85)', 51, 65, 85, 1.0];
        yield 'rgb spaces' => ['rgb(51 65 85)', 51, 65, 85, 1.0];
        yield 'rgba' => ['rgba(51, 65, 85, 0.5)', 51, 65, 85, 0.5];
        yield 'rgb slash alpha' => ['rgb(51 65 85 / 0.25)', 51, 65, 85, 0.25];
        yield 'named' => ['slate-700', 51, 65, 85, 1.0];
        yield 'black' => ['black', 0, 0, 0, 1.0];
        yield 'white' => ['white', 255, 255, 255, 1.0];
        yield 'transparent' => ['transparent', 0, 0, 0, 0.0];
        yield 'whitespace' => ['  #000 ', 0, 0, 0, 1.0];
    }

    #[DataProvider('valid')]
    public function test_parse_valid(string $in, int $r, int $g, int $b, float $a): void
    {
        $c = Color::parse($in);
        self::assertSame([$r, $g, $b], [$c->r, $c->g, $c->b]);
        self::assertEqualsWithDelta($a, $c->alpha, 0.001);
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function invalid(): iterable
    {
        yield 'typo in name' => ['slaet-700', 'slate-700'];
        yield 'missing hyphen' => ['slate500', 'slate-500'];
        yield 'unknown shade' => ['slate-550', null];
        yield 'bad hex length' => ['#12345', null];
        yield 'channel out of range' => ['rgb(300, 0, 0)', null];
        yield 'alpha out of range' => ['rgba(0, 0, 0, 2)', null];
        yield 'bare family' => ['red', 'red-500'];
        yield 'bare family slate' => ['slate', 'slate-500'];
        yield 'empty' => ['', null];
        yield 'rgb without separators' => ['rgb(100200100)', null];
        yield 'rgb mixed separators' => ['rgb(1,2 3)', null];
        yield 'rgb alpha without rgba' => ['rgb(1 2 3, 0.5)', null];
    }

    #[DataProvider('invalid')]
    public function test_parse_invalid(string $in, ?string $suggestion): void
    {
        try {
            Color::parse($in);
            self::fail('Expected InvalidValue');
        } catch (InvalidValue $e) {
            self::assertSame('color', $e->kind);
            self::assertSame($suggestion, $e->suggestion);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidHex(): iterable
    {
        yield 'double hash' => ['##fff'];
        yield 'missing hash' => ['fff'];
        yield 'missing hash long' => ['334155'];
    }

    #[DataProvider('invalidHex')]
    public function test_hex_requires_exactly_one_hash(string $in): void
    {
        try {
            Color::hex($in);
            self::fail('Expected InvalidValue');
        } catch (InvalidValue $e) {
            self::assertSame('color', $e->kind);
        }
    }

    public function test_named_is_case_insensitive(): void
    {
        self::assertSame('#334155', (string) Color::named('Slate-700'));
    }

    public function test_eight_digit_hex_round_trips(): void
    {
        self::assertSame('#33415580', (string) Color::parse('#33415580'));
    }

    public function test_basic_names_still_parse(): void
    {
        self::assertSame('#000000', (string) Color::parse('black'));
        self::assertSame('#ffffff', (string) Color::parse('white'));
        self::assertTrue(Color::parse('transparent')->isTransparent());
    }

    public function test_non_finite_alpha_rejected(): void
    {
        foreach ([NAN, INF] as $a) {
            try {
                Color::rgb(0, 0, 0, $a);
                self::fail('rgb accepted');
            } catch (InvalidValue $e) {
                self::assertSame('color', $e->kind);
            }
            try {
                Color::black()->withAlpha($a);
                self::fail('withAlpha accepted');
            } catch (InvalidValue $e) {
                self::assertSame('color', $e->kind);
            }
        }
    }

    public function test_hex_direct(): void
    {
        $c = Color::hex('#fff');
        self::assertSame([255, 255, 255], [$c->r, $c->g, $c->b]);
    }

    public function test_helpers(): void
    {
        self::assertSame('#334155', (string) Color::rgb(51, 65, 85));
        self::assertSame('#33415580', (string) Color::rgb(51, 65, 85, 0.5));
        self::assertTrue(Color::black()->isOpaque());
        self::assertTrue(Color::transparent()->isTransparent());
        self::assertSame(0.5, Color::white()->withAlpha(0.5)->alpha);
        self::assertEqualsWithDelta([0.2, 0.2549, 0.3333], Color::hex('#334155')->toUnitRgb(), 0.0001);
        $this->expectException(InvalidValue::class);
        Color::rgb(256, 0, 0);
    }
}
