<?php

declare(strict_types=1);

namespace Papercut\Support;

use Papercut\Exception\InvalidValue;
use Papercut\Export\FidelityLevel;
use Papercut\Markup\Note;

/**
 * Invalid UTF-8 in text a developer passed to a builder: replaced with U+FFFD once, at the boundary, and reported.
 * The twin of Papercut\Math\Utf8 in papercut/math, which cannot depend on this package; keep the two in step.
 *
 * @internal Part of Papercut's engine, not its public API. It may change in any release.
 */
final class Utf8
{
    public const string NOTE = 'invalid UTF-8 replaced with U+FFFD';

    /** $text with every invalid byte sequence replaced by U+FFFD; $changed is set when anything was. */
    public static function scrub(string $text, bool &$changed): string
    {
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }
        $changed = true;

        $previous = mb_substitute_character();
        mb_substitute_character(0xFFFD);
        try {
            return mb_scrub($text, 'UTF-8');
        } finally {
            mb_substitute_character($previous);
        }
    }

    /**
     * For values that cannot carry a replacement character (document metadata, a page number format).
     *
     * @throws InvalidValue when $value is not valid UTF-8
     */
    public static function require(string $value, string $what): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            $changed = false;
            throw InvalidValue::for($what, self::scrub($value, $changed))->because('not valid UTF-8');
        }

        return $value;
    }

    public static function note(): Note
    {
        return new Note(FidelityLevel::Approximated, self::NOTE);
    }
}
