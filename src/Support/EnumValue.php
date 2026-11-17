<?php

declare(strict_types=1);

namespace Quoin\Support;

use Quoin\Exception\InvalidValue;

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
     * An enum with a static aliases() method (string => case) also accepts those strings, with or
     * without hyphens. An enum case passes through.
     *
     * @template T of \BackedEnum
     * @param class-string<T> $enum
     * @return T
     * @param string|null $what the setter, such as "QrCode::shape()", named in the error
     * @throws InvalidValue naming $what and the allowed values, with a "did you mean" suggestion for an unknown value
     */
    public static function from(string $enum, \BackedEnum|string $value, ?string $what = null): \BackedEnum
    {
        if ($value instanceof $enum) {
            return $value;
        }
        if ($value instanceof \BackedEnum) {
            throw self::reject($enum, $value::class, null, $what);
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
        $aliases = [];
        foreach (method_exists($enum, 'aliases') ? (array) $enum::aliases() : [] as $alias => $case) {
            if (is_string($alias) && $case instanceof $enum) {
                $aliases[$alias] = $case;
            }
        }
        foreach ($aliases as $alias => $case) {
            if ($normalized === $alias || $normalized === str_replace('-', '', $alias)) {
                return $case;
            }
        }
        // Candidates are compared in lower case and shown as spelled. A single letter is no useful suggestion.
        $shown = [];
        foreach (array_keys($aliases) as $alias) {
            $shown[$alias] = (string) $alias;
        }
        foreach ($cases as $case) {
            foreach ([(string) $case->value, $case->name] as $spelling) {
                if (strlen($spelling) > 1) {
                    $shown[strtolower($spelling)] ??= $spelling;
                }
            }
        }
        $closest = Suggest::closest($normalized, array_keys($shown));
        throw self::reject($enum, $value, $closest === null ? null : $shown[$closest], $what);
    }

    /** @param class-string<\BackedEnum> $enum */
    private static function reject(string $enum, string $value, ?string $suggestion, ?string $what): InvalidValue
    {
        $e = InvalidValue::for('value', $value, $suggestion);
        if ($what !== null) {
            $e = $e->withContext($what);
        }

        return $e->because('expected one of ' . implode(', ', array_map(static fn (\BackedEnum $c): string => (string) $c->value, $enum::cases())));
    }
}
