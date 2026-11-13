<?php

declare(strict_types=1);

namespace Papercut\Concerns;

use InvalidArgumentException;
use Papercut\Exception\InvalidVariant;

/**
 * The rules for tone and variant names, shared by the block Badge and the inline Span::badge().
 *
 * @internal Part of Papercut's engine, not its public API. It may change in any release.
 */
final class NameRules
{
    /** A tone is one word without `?`; returns it trimmed. */
    public static function tone(string $tone): string
    {
        $tone = trim($tone);
        if ($tone === '') {
            throw new InvalidArgumentException('Badge tone must not be empty.');
        }
        if (preg_match('/\s/', $tone) === 1) {
            throw new InvalidArgumentException("Badge tone must be a single word, got '$tone'.");
        }
        if (str_contains($tone, '?')) {
            throw new InvalidArgumentException("Badge tone must not contain '?', got '$tone'.");
        }
        return $tone;
    }

    /**
     * The words of a space-separated variant list, each made of a-z, 0-9 and hyphens, none equal to a name segment of
     * $partNames or to one of $toneNames.
     *
     * @param list<string> $partNames
     * @param list<string> $toneNames
     * @return non-empty-list<string>
     */
    public static function variants(string $names, array $partNames, array $toneNames): array
    {
        $words = preg_split('/\s+/', trim($names), -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false || $words === []) {
            throw new InvalidArgumentException('A variant must not be empty.');
        }
        foreach ($words as $word) {
            if (preg_match('/^[a-z0-9-]+$/', $word) !== 1) {
                throw new InvalidArgumentException("A variant is a lowercase word of a-z, 0-9 and hyphens, got '$word'.");
            }
        }
        $segments = [];
        foreach ($partNames as $part) {
            array_push($segments, ...explode('.', $part));
        }
        foreach ($words as $word) {
            if (in_array($word, $segments, true)) {
                throw new InvalidVariant("The variant '$word' collides with a part name of this decorator (a name segment of: " . implode(', ', $partNames) . '); pick another word.');
            }
        }
        foreach ($words as $word) {
            if (in_array($word, $toneNames, true)) {
                throw new InvalidVariant("The variant '$word' is one of this decorator's built-in tones (" . implode(', ', $toneNames) . "); use tone('$word') for the tone's colours, or pick another variant word.");
            }
        }
        return $words;
    }
}
