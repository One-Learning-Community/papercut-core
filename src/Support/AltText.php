<?php

declare(strict_types=1);

namespace Papercut\Support;

/** @internal Part of Papercut's engine, not its public API. It may change in any release. */
final class AltText
{
    /** Invalid UTF-8 replaced with U+FFFD, runs of whitespace collapsed to one space, and trimmed. */
    public static function normalize(string $text): string
    {
        $changed = false;
        $text = Utf8::scrub($text, $changed);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
