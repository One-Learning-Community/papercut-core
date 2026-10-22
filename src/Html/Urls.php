<?php

declare(strict_types=1);

namespace Papercut\Html;

/** URL rules shared by images, font stylesheets and font files. */
final class Urls
{
    /** Not empty, no control characters and no edge whitespace: browsers drop those inside schemes. */
    public static function clean(string $url): bool
    {
        return $url !== '' && preg_match('/[\x00-\x1f\x7f]/', $url) !== 1 && trim($url) === $url;
    }

    /** A path with no scheme, no protocol-relative start and no backslash start. */
    public static function relative(string $url): bool
    {
        return preg_match('~^(?![a-z][a-z0-9+.-]*:|[/\\\\]{2}|\\\\)~i', $url) === 1;
    }

    /** An http or https URL, or a relative path. */
    public static function safeStylesheet(string $url): bool
    {
        return self::clean($url) && (preg_match('~^https?:~i', $url) === 1 || self::relative($url));
    }

    /** As {@see safeStylesheet()}, plus data:font/ URIs. */
    public static function safeFontSrc(string $url): bool
    {
        return self::safeStylesheet($url) || (self::clean($url) && preg_match('~^data:font/~i', $url) === 1);
    }
}
