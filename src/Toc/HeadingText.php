<?php

declare(strict_types=1);

namespace Papercut\Toc;

use Papercut\{Span, Text};

/**
 * What a heading says and how deep it sits. Shared by bookmarks and the table of contents.
 *
 * @internal
 */
final class HeadingText
{
    /** The text's spans joined, line breaks as spaces. */
    public static function plain(Text $t): string
    {
        return implode('', array_map(static fn (Span $s): string => $s->lineBreak ? ' ' : $s->text, $t->spanList()));
    }

    /** 1 to 4 for an h1 to h4 preset, otherwise null. */
    public static function level(Text $t): ?int
    {
        return in_array($t->preset(), ['h1', 'h2', 'h3', 'h4'], true) ? (int) substr((string) $t->preset(), 1) : null;
    }
}
