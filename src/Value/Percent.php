<?php

declare(strict_types=1);

namespace Papercut\Value;

use Papercut\Exception\InvalidValue;

final readonly class Percent implements \Stringable
{
    private function __construct(public float $value)
    {
    }

    public static function of(float $value): self
    {
        if (!is_finite($value)) {
            throw InvalidValue::for('percentage', InvalidValue::number($value));
        }
        return new self($value);
    }

    public static function parse(string $input): self
    {
        $s = trim($input);
        if (preg_match('/^(-?(?:\d+(?:\.\d+)?|\.\d+))%$/', $s, $m)) {
            if (!is_finite((float) $m[1])) {
                throw InvalidValue::for('percentage', $input);
            }
            return new self((float) $m[1]);
        }
        if (preg_match('/^(-?(?:\d+(?:\.\d+)?|\.\d+))\s+%$/', $s, $m)) {
            throw InvalidValue::for('percentage', $input, $m[1] . '%');
        }
        throw InvalidValue::for('percentage', $input);
    }

    public function resolve(float $basisPoints): float
    {
        return $basisPoints * $this->value / 100;
    }

    public function __toString(): string
    {
        return rtrim(rtrim(number_format($this->value, 4, '.', ''), '0'), '.') . '%';
    }
}
