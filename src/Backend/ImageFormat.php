<?php

declare(strict_types=1);

namespace Quoin\Backend;

/**
 * Tells raster formats apart by their leading bytes. PNG and JPEG embed in
 * PDF as they are; the others are converted first.
 *
 * @internal
 */
final class ImageFormat
{
    /** @var array<string, string> MIME type => display name, in the order error messages list them */
    public const array NAMES = [
        'image/png' => 'PNG',
        'image/jpeg' => 'JPEG',
        'image/webp' => 'WebP',
        'image/gif' => 'GIF',
        'image/bmp' => 'BMP',
        'image/avif' => 'AVIF',
    ];

    /** The MIME type of $bytes, or null for a format that is not read. */
    public static function sniff(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            str_starts_with($bytes, 'GIF87a'), str_starts_with($bytes, 'GIF89a') => 'image/gif',
            str_starts_with($bytes, 'BM') && strlen($bytes) >= 26 => 'image/bmp',
            substr($bytes, 4, 4) === 'ftyp' && in_array(substr($bytes, 8, 4), ['avif', 'avis'], true) => 'image/avif',
            default => null,
        };
    }

    /**
     * Whether $bytes is a whole image of a readable format, judged by structure
     * (not just the magic number): PNG chunks up to IEND, JPEG markers to a scan, a
     * sane GIF or WebP header. GIF, WebP, BMP and AVIF are also decoded with GD under
     * a quiet handler, because GD is what reads them. Truncated or garbage bytes fail
     * without any PHP diagnostic reaching the caller.
     */
    public static function intact(string $bytes): bool
    {
        $mime = self::sniff($bytes);
        return match ($mime) {
            null => false,
            'image/png' => self::pngIntact($bytes),
            'image/jpeg' => self::jpegIntact($bytes),
            'image/gif' => self::gifIntact($bytes) && self::decodes($bytes, $mime),
            'image/webp' => self::webpIntact($bytes) && self::decodes($bytes, $mime),
            default => self::decodes($bytes, $mime),
        };
    }

    /**
     * Runs $work with PHP diagnostics swallowed, so a decoder that warns about bad bytes
     * does not reach the caller's error handler.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function quietly(callable $work): mixed
    {
        set_error_handler(static fn (): bool => true);
        try {
            return $work();
        } finally {
            restore_error_handler();
        }
    }

    /** Whether this GD build can decode $mime (a format GD reads, and GD loaded with it). */
    public static function gdReads(string $mime): bool
    {
        $bit = ['image/gif' => 1, 'image/webp' => 32, 'image/bmp' => 64, 'image/avif' => 256][$mime] ?? null;
        return $bit !== null && function_exists('imagecreatefromstring') && (imagetypes() & $bit) !== 0;
    }

    /** Whether GD (formats converted with it) decodes and re-encodes $bytes; true when GD is absent (the loader reports that). */
    private static function decodes(string $bytes, string $mime): bool
    {
        if (!self::gdReads($mime)) {
            return true; // not damaged: the loader says GD cannot read it
        }
        $warned = false;
        set_error_handler(static function () use (&$warned): bool {
            $warned = true; // a decoder that complains has met damaged data, even when it still returns an image
            return true;
        });
        try {
            $im = imagecreatefromstring($bytes);
            if ($im === false) {
                return false;
            }
            ob_start();
            $ok = imagepng($im);
            return ob_get_clean() !== '' && $ok && !$warned;
        } finally {
            restore_error_handler();
        }
    }

    /** @return array<int, int> unpack() that never fails (empty when the bytes are short) */
    private static function u(string $format, string $b, int $offset): array
    {
        return strlen($b) - $offset >= 4 ? (unpack($format, $b, $offset) ?: []) : [];
    }

    private static function pngIntact(string $b): bool
    {
        $len = strlen($b);
        $pos = 8;
        $first = true;
        $data = false;
        while ($pos + 12 <= $len) {
            $n = self::u('N', $b, $pos)[1] ?? 0;
            $type = substr($b, $pos + 4, 4);
            if ($pos + 12 + $n > $len) {
                return false;
            }
            if ($first) {
                if ($type !== 'IHDR' || $n < 13) {
                    return false;
                }
                $dim = self::u('N2', $b, $pos + 8);
                if (($dim[1] ?? 0) < 1 || ($dim[2] ?? 0) < 1) {
                    return false;
                }
                $first = false;
            } elseif ($type === 'IDAT') {
                $data = true;
            } elseif ($type === 'IEND') {
                return $data;
            }
            $pos += 12 + $n;
        }
        return false;
    }

    private static function jpegIntact(string $b): bool
    {
        $len = strlen($b);
        $pos = 2;
        $frame = false;
        while ($pos + 4 <= $len) {
            if ($b[$pos] !== "\xFF") {
                return false;
            }
            $m = ord($b[$pos + 1]);
            if ($m === 0xFF) {
                $pos++;
                continue;
            }
            if ($m === 0xD9) {
                return false; // EOI before any scan
            }
            if ($m === 0x01 || ($m >= 0xD0 && $m <= 0xD8)) {
                $pos += 2;
                continue;
            }
            $n = self::u('n', $b, $pos + 2)[1] ?? 0;
            if ($n < 2 || $pos + 2 + $n > $len) {
                return false;
            }
            if ($m >= 0xC0 && $m <= 0xCF && !in_array($m, [0xC4, 0xC8, 0xCC], true)) {
                if ($n < 8) {
                    return false;
                }
                $d = self::u('n2', $b, $pos + 5);
                if (($d[1] ?? 0) < 1 || ($d[2] ?? 0) < 1) {
                    return false;
                }
                $frame = true;
            } elseif ($m === 0xDA) {
                return $frame; // a missing EOI is tolerated (some encoders omit it)
            }
            $pos += 2 + $n;
        }
        return false;
    }

    private static function gifIntact(string $b): bool
    {
        if (strlen($b) < 14) {
            return false;
        }
        $d = self::u('v2', $b, 6);
        return ($d[1] ?? 0) > 0 && ($d[2] ?? 0) > 0;
    }

    private static function webpIntact(string $b): bool
    {
        if (strlen($b) < 20 || !in_array(substr($b, 12, 4), ['VP8 ', 'VP8L', 'VP8X'], true)) {
            return false;
        }
        return (self::u('V', $b, 4)[1] ?? PHP_INT_MAX) + 8 <= strlen($b);
    }

    /** "Unsupported image format (expected PNG, JPEG, … or AVIF)." */
    public static function unsupported(): string
    {
        $names = array_values(self::NAMES);
        $last = array_pop($names);
        return sprintf('Unsupported image format (expected %s or %s).', implode(', ', $names), $last);
    }
}
