<?php

declare(strict_types=1);

namespace Papercut\Style;

/** Helpers for style names, which may be layers separated by spaces (`pre mine`). */
final class StyleName
{
    /** Whether the first layer of the (possibly layered) style name is exactly $word. */
    public static function leads(?string $name, string $word): bool
    {
        if ($name === null) {
            return false;
        }
        $name = ltrim($name);
        $end = strcspn($name, " \t\n\r");

        return substr($name, 0, $end) === $word;
    }
}
