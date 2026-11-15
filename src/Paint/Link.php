<?php

declare(strict_types=1);

namespace Papercut\Paint;

use Papercut\Exception\InvalidValue;

/** @internal Part of the display list between layout and the PDF writer. It may change in any release. */
final readonly class Link implements Op
{
    /** The validated URI, leading whitespace removed. */
    public string $uri;

    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        string $uri,
    ) {
        if (!is_finite($x) || !is_finite($y) || !is_finite($width) || !is_finite($height)) {
            throw new \InvalidArgumentException('Link received a non-finite number.');
        }
        if (!self::allowed($uri)) {
            throw InvalidValue::for('link', $uri)->because('only http, https, mailto, tel and #fragment links are allowed');
        }
        $this->uri = ltrim($uri);
    }

    /**
     * The address without the whitespace around it (as markup trims it), or InvalidValue naming $context
     * for a control character inside it or a scheme other than the allowed ones.
     *
     * @throws InvalidValue
     */
    public static function checked(string $uri, string $context): string
    {
        $uri = trim($uri, " \t\n\r\f");
        if (self::hasControlCharacter($uri)) {
            throw InvalidValue::for('link', addcslashes($uri, "\x00..\x1f"))->withContext($context)->because('a link cannot contain NUL, CR or LF');
        }
        if (!self::allowed($uri)) {
            throw InvalidValue::for('link', $uri)->withContext($context)->because('only http, https, mailto, tel and #fragment links are allowed');
        }
        return $uri;
    }

    /** Whether the URI has a control character (NUL, CR or LF), which no link can carry. */
    public static function hasControlCharacter(string $uri): bool
    {
        return preg_match('/[\x00\r\n]/', $uri) === 1;
    }

    /** http, https, mailto, tel or a #fragment, case-insensitive, ignoring leading whitespace. */
    public static function allowed(string $uri): bool
    {
        return preg_match('~^(?:(?:https?|mailto|tel):|#)~i', ltrim($uri)) === 1;
    }
}
