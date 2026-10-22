<?php

declare(strict_types=1);

namespace Papercut\Tests\Exception;

use Papercut\Exception\{FontNotFound, ImageUnreadable, InvalidValue};
use Papercut\PapercutException;
use PHPUnit\Framework\TestCase;

final class InvalidValueTest extends TestCase
{
    public function test_other_exceptions_are_library_exceptions(): void
    {
        self::assertInstanceOf(PapercutException::class, new FontNotFound('x'));
        self::assertInstanceOf(PapercutException::class, new ImageUnreadable('x'));
    }

    public function test_message_without_suggestion(): void
    {
        $e = InvalidValue::for('length', '20px!');
        self::assertInstanceOf(PapercutException::class, $e);
        self::assertSame('Invalid length "20px!".', $e->getMessage());
    }

    public function test_message_with_suggestion(): void
    {
        $e = InvalidValue::for('length', '20 mm', '20mm');
        self::assertSame('Invalid length "20 mm". Did you mean "20mm"?', $e->getMessage());
        self::assertSame('20mm', $e->suggestion);
    }

    public function test_with_context_returns_new_exception_with_context_in_message(): void
    {
        $base = InvalidValue::for('length', '20 mm', '20mm');
        $e = $base->withContext('margin');
        self::assertNotSame($base, $e);
        self::assertSame('Invalid length "20 mm" for margin. Did you mean "20mm"?', $e->getMessage());
        self::assertSame($base, $e->getPrevious());
        self::assertSame('margin', $e->context);
    }

    public function test_context_and_location(): void
    {
        $base = InvalidValue::for('edges', '20 mm', '20mm');
        $e = $base->withContext('margin')->within('on <x-doc::page>');
        self::assertSame('Invalid edges "20 mm" for margin on <x-doc::page>. Did you mean "20mm"?', $e->getMessage());
        self::assertSame(['on <x-doc::page>'], $e->locations);
        self::assertSame('margin', $e->context);
        $mid = $e->getPrevious();
        self::assertInstanceOf(InvalidValue::class, $mid);
        self::assertSame('margin', $mid->context);
        self::assertSame($base, $mid->getPrevious());
    }

    public function test_location_only_and_repeated_locations_append_left_to_right(): void
    {
        self::assertSame(
            'Invalid edges "20 mm" on <x-doc::view>.',
            InvalidValue::for('edges', '20 mm')->within('on <x-doc::view>')->getMessage(),
        );
        $e = InvalidValue::for('edges', 'x')->within('on <a>')->within('in <b>');
        self::assertSame('Invalid edges "x" on <a> in <b>.', $e->getMessage());
        self::assertSame(['on <a>', 'in <b>'], $e->locations);
    }

    public function test_because_adds_reason_and_chains(): void
    {
        $base = InvalidValue::for('inline markup', '<blink>x</blink>');
        $e = $base->because('<blink> is not allowed');
        self::assertSame('Invalid inline markup "<blink>x</blink>": <blink> is not allowed.', $e->getMessage());
        self::assertSame('<blink> is not allowed', $e->reason);
        self::assertSame($base, $e->getPrevious());
        self::assertNull($base->reason);
    }

    public function test_full_composition_order(): void
    {
        $e = InvalidValue::for('edges', 'q', 'q1')->withContext('margin')->within('on <p>')->because('bad');
        self::assertSame('Invalid edges "q" for margin on <p>: bad. Did you mean "q1"?', $e->getMessage());
    }

    public function test_number_formats_non_finite_values_without_casting(): void
    {
        self::assertSame('NAN', InvalidValue::number(NAN));
        self::assertSame('INF', InvalidValue::number(INF));
        self::assertSame('-INF', InvalidValue::number(-INF));
        self::assertSame('1.5', InvalidValue::number(1.5));
        self::assertSame('-3', InvalidValue::number(-3));
    }

    public function test_for_accepts_previous(): void
    {
        $inner = InvalidValue::for('length', 'x');
        self::assertSame($inner, InvalidValue::for('edges', 'x', null, $inner)->getPrevious());
    }
}
