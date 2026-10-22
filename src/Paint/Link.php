<?php

declare(strict_types=1);

namespace Papercut\Paint;

use Papercut\Exception\InvalidValue;

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

    /** http, https, mailto, tel or a #fragment, case-insensitive, ignoring leading whitespace. */
    public static function allowed(string $uri): bool
    {
        return preg_match('~^(?:(?:https?|mailto|tel):|#)~i', ltrim($uri)) === 1;
    }
}
