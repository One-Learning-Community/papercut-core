<?php

declare(strict_types=1);

namespace Papercut\Support;

use Papercut\Exception\InvalidValue;

/**
 * Turns an option given as its string value into the enum case, for the fluent setters and the
 * style attributes.
 *
 * @internal
 */
final class EnumValue
{
    /**
     * The case whose backing value or name equals $value, ignoring case and surrounding whitespace.
     * An enum case passes through.
     *
     * @template T of \BackedEnum
     * @param class-string<T> $enum
     * @return T
     * @throws InvalidValue with a "did you mean" suggestion for an unknown value
     */
    public static function from(string $enum, \BackedEnum|string $value): \BackedEnum
    {
        if ($value instanceof $enum) {
            return $value;
        }
        if ($value instanceof \BackedEnum) {
            throw InvalidValue::for('value', $value::class);
        }
        $normalized = strtolower(trim($value));
        $cases = $enum::cases();
        foreach ($cases as $case) {
            if (strtolower((string) $case->value) === $normalized) {
                return $case;
            }
        }
        // The case name too, so 'high' works for a case whose value is 'H'.
        foreach ($cases as $case) {
            if (strtolower($case->name) === $normalized) {
                return $case;
            }
        }
        // Candidates are compared in lower case and shown as spelled. A single letter is no useful suggestion.
        $shown = [];
        foreach ($cases as $case) {
            foreach ([(string) $case->value, $case->name] as $spelling) {
                if (strlen($spelling) > 1) {
                    $shown[strtolower($spelling)] ??= $spelling;
                }
            }
        }
        $closest = Suggest::closest($normalized, array_keys($shown));
        throw InvalidValue::for('value', $value, $closest === null ? null : $shown[$closest]);
    }
}
