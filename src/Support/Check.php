<?php

declare(strict_types=1);

namespace Quoin\Support;

use Quoin\Exception\InvalidValue;
use Quoin\Value\{Auto, Edges, Length, Percent};

/**
 * Checks a developer's number when it is set, so a bad one fails at the call that gave it and
 * names that call, instead of failing deep in export or drawing something wrong. Every check
 * returns the value it was given. $what names the setter, such as "View::opacity()".
 *
 * @internal
 */
final class Check
{
    /** A finite number: not NAN, INF or -INF. */
    public static function finite(float $v, string $what): float
    {
        if (!is_finite($v)) {
            throw self::fail('number', $v, $what, 'must be a finite number');
        }
        return $v;
    }

    /** Zero or more. */
    public static function nonNegative(float $v, string $what): float
    {
        self::finite($v, $what);
        if ($v < 0.0) {
            throw self::fail('number', $v, $what, 'must be 0 or more');
        }
        return $v;
    }

    /** More than zero. */
    public static function positive(float $v, string $what): float
    {
        self::finite($v, $what);
        if ($v <= 0.0) {
            throw self::fail('number', $v, $what, 'must be more than 0');
        }
        return $v;
    }

    /** From $min to $max, both included. */
    public static function range(float $v, float $min, float $max, string $what): float
    {
        $reason = sprintf('between %s and %s', InvalidValue::number($min), InvalidValue::number($max));
        if (!is_finite($v)) {
            throw self::fail('number', $v, $what, 'must be a finite number ' . $reason);
        }
        if ($v < $min || $v > $max) {
            throw self::fail('number', $v, $what, 'must be ' . $reason);
        }
        return $v;
    }

    /** A whole number from $min to $max, both included. */
    public static function intRange(int $v, int $min, int $max, string $what): int
    {
        if ($v < $min || $v > $max) {
            throw InvalidValue::for('number', (string) $v)->withContext($what)->because(sprintf('must be between %d and %d', $min, $max));
        }
        return $v;
    }

    /**
     * A size or distance that cannot be negative; with $positive it cannot be zero either.
     * Auto passes. An em or lh length is checked by its factor, since it has no points yet.
     *
     * @template T of Length|Percent|Auto
     * @param T $v
     * @return T
     */
    public static function size(Length|Percent|Auto $v, string $what, bool $positive = false): Length|Percent|Auto
    {
        if ($v instanceof Auto) {
            return $v;
        }
        $n = match (true) {
            $v instanceof Percent => $v->value,
            $v->isRelative() => $v->factor,
            default => $v->points,
        };
        if ($n < 0.0 || ($positive && $n === 0.0)) {
            $kind = $v instanceof Percent ? 'percentage' : 'length';
            throw self::fail($kind, $v, $what, $positive ? 'must be more than 0' : 'cannot be negative');
        }
        return $v;
    }

    /** Four edges (padding, border width) that cannot be negative. */
    public static function edges(Edges $e, string $what): Edges
    {
        foreach ([$e->top, $e->right, $e->bottom, $e->left] as $side) {
            self::size($side, $what);
        }
        return $e;
    }

    private static function fail(string $kind, float|Length|Percent $v, string $what, string $reason): InvalidValue
    {
        $shown = is_float($v) ? InvalidValue::number($v) : (string) $v;
        return InvalidValue::for($kind, $shown)->withContext($what)->because($reason);
    }
}
