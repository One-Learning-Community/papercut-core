<?php

declare(strict_types=1);

namespace Papercut\Value;

use Papercut\Exception\InvalidValue;

final readonly class Length implements \Stringable
{
    private const string NUMBER = '-?(?:\d+(?:\.\d+)?|\.\d+)';

    /**
     * @param float $points absolute size; NAN for an em/lh length until ComputedStyle resolves it
     * @param 'em'|'lh'|null $relative
     */
    private function __construct(public float $points, public ?string $relative = null, public float $factor = 0.0)
    {
    }

    /** A length in multiples of the element's font size, resolved when styles are computed. */
    public static function em(float $factor): self
    {
        return self::relative($factor, 'em');
    }

    /** A length in multiples of the element's line height (font size × line-height), resolved when styles are computed. */
    public static function lh(float $factor): self
    {
        return self::relative($factor, 'lh');
    }

    /** @param 'em'|'lh' $unit */
    private static function relative(float $factor, string $unit): self
    {
        if (!is_finite($factor)) {
            throw InvalidValue::for('length', InvalidValue::number($factor) . $unit);
        }
        return new self(NAN, $unit, $factor);
    }

    public function isRelative(): bool
    {
        return $this->relative !== null;
    }

    /** This length, if it is absolute; em and lh are rejected outside style properties. */
    public function absolute(string $kind = 'length'): self
    {
        if ($this->relative !== null) {
            throw InvalidValue::for($kind, (string) $this)->because('em and lh only work in style properties');
        }
        return $this;
    }

    /** This length in points, given the element's font size and line height (both in points). */
    public function resolve(float $em, float $lh): self
    {
        return match ($this->relative) {
            null => $this,
            'em' => self::points($this->factor * $em),
            'lh' => self::points($this->factor * $lh),
        };
    }

    public static function of(float $value, Unit $unit): self
    {
        $points = $value * $unit->points();
        if (!is_finite($value) || !is_finite($points)) {
            throw InvalidValue::for('length', InvalidValue::number($value));
        }
        return new self($points);
    }

    public static function points(float $pt): self
    {
        if (!is_finite($pt)) {
            throw InvalidValue::for('length', InvalidValue::number($pt));
        }
        return new self($pt);
    }

    public static function zero(): self
    {
        return new self(0.0);
    }

    /**
     * @param bool $relative accept em and lh, which only style properties resolve
     */
    public static function parse(string $input, bool $relative = false): self
    {
        $s = trim($input);
        if (preg_match('/^(' . self::NUMBER . ')(em|lh)$/', $s, $m)) {
            if (!$relative) {
                throw InvalidValue::for('length', $input)->because('em and lh only work in style properties');
            }
            /** @var 'em'|'lh' $unit */
            $unit = $m[2];
            return self::relative((float) $m[1], $unit);
        }
        if (preg_match('/^(' . self::NUMBER . ')(pt|px|mm|cm|in)$/', $s, $m)) {
            $number = (float) $m[1];
            if (!is_finite($number) || !is_finite($number * Unit::from($m[2])->points())) {
                throw InvalidValue::for('length', $input);
            }
            return self::of($number, Unit::from($m[2]));
        }
        if (preg_match('/^' . self::NUMBER . '$/', $s)) {
            if ((float) $s === 0.0) {
                return self::zero();
            }
            throw InvalidValue::for('length', $input, $s . 'pt');
        }
        if (preg_match('/^(' . self::NUMBER . ')\s+(pt|px|mm|cm|in)$/i', $s, $m)) {
            throw InvalidValue::for('length', $input, $m[1] . strtolower($m[2]));
        }
        if (preg_match('/^(' . self::NUMBER . ')(pt|px|mm|cm|in)$/i', $s, $m)) {
            throw InvalidValue::for('length', $input, $m[1] . strtolower($m[2]));
        }
        throw InvalidValue::for('length', $input);
    }

    public function plus(Length $other): self
    {
        self::noArithmeticOnRelative($this, $other);
        return self::points($this->points + $other->points);
    }

    public function minus(Length $other): self
    {
        self::noArithmeticOnRelative($this, $other);
        return self::points($this->points - $other->points);
    }

    public function scale(float $factor): self
    {
        if ($this->relative !== null) {
            return self::relative($this->factor * $factor, $this->relative);
        }
        return self::points($this->points * $factor);
    }

    public function equals(Length $other, float $epsilon = 1e-6): bool
    {
        if ($this->relative !== null || $other->relative !== null) {
            return $this->relative === $other->relative && abs($this->factor - $other->factor) <= $epsilon;
        }
        return abs($this->points - $other->points) <= $epsilon;
    }

    public function __toString(): string
    {
        $n = rtrim(rtrim(number_format($this->relative === null ? $this->points : $this->factor, 4, '.', ''), '0'), '.');
        return ($n === '-0' ? '0' : $n) . ($this->relative ?? 'pt');
    }

    private static function noArithmeticOnRelative(Length ...$lengths): void
    {
        foreach ($lengths as $l) {
            if ($l->relative !== null) {
                throw new \LogicException(sprintf('Cannot do arithmetic on %s before it is resolved to points.', $l));
            }
        }
    }
}
