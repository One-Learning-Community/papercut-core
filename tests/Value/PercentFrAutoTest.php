<?php

declare(strict_types=1);

namespace Papercut\Tests\Value;

use Papercut\Exception\InvalidValue;
use Papercut\Value\{Auto, Fr, Percent};
use PHPUnit\Framework\TestCase;

use function Papercut\{fr, percent};

final class PercentFrAutoTest extends TestCase
{
    public function test_percent(): void
    {
        self::assertSame(50.0, Percent::parse('50%')->value);
        self::assertSame(100.0, Percent::parse('50%')->resolve(200));
        self::assertSame('12.5%', (string) percent(12.5));
        $this->assertInvalid(fn () => Percent::parse('50 %'), 'percentage', '50%');
        $this->assertInvalid(fn () => Percent::parse('half'), 'percentage', null);
    }

    public function test_fr(): void
    {
        self::assertSame(3.0, Fr::parse('3fr')->value);
        self::assertSame(0.5, Fr::parse('.5fr')->value);
        self::assertSame('2fr', (string) fr(2));
        $this->assertInvalid(fn () => Fr::parse('3 fr'), 'fraction', '3fr');
        $this->assertInvalid(fn () => Fr::parse('0fr'), 'fraction', null);
        $this->assertInvalid(fn () => Fr::parse('-1fr'), 'fraction', null);
        $this->assertInvalid(fn () => Fr::parse('1' . str_repeat('0', 400) . 'fr'), 'fraction', null);
        $this->assertInvalid(fn () => Fr::parse('3FR'), 'fraction', '3fr');
        $this->assertInvalid(fn () => Fr::of(NAN), 'fraction', null);
        $this->assertInvalid(fn () => Fr::of(INF), 'fraction', null);
        $this->assertInvalid(fn () => Fr::of(0), 'fraction', null);
    }

    public function test_percent_rejects_non_finite(): void
    {
        $this->assertInvalid(fn () => Percent::parse('1' . str_repeat('0', 400) . '%'), 'percentage', null);
        $this->assertInvalid(fn () => Percent::of(INF), 'percentage', null);
        $this->assertInvalid(fn () => Percent::of(NAN), 'percentage', null);
    }

    public function test_auto_is_singleton(): void
    {
        self::assertSame(Auto::instance(), Auto::instance());
        self::assertSame('auto', (string) Auto::instance());
    }

    private function assertInvalid(callable $fn, string $kind, ?string $suggestion): void
    {
        try {
            $fn();
            self::fail('Expected InvalidValue');
        } catch (InvalidValue $e) {
            self::assertSame($kind, $e->kind);
            self::assertSame($suggestion, $e->suggestion);
        }
    }
}
