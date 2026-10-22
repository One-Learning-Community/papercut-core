<?php

declare(strict_types=1);

namespace Papercut\Backend;

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

    /** The MIME type of $bytes, or null for a format Papercut does not read. */
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

    /** "Unsupported image format (expected PNG, JPEG, … or AVIF)." */
    public static function unsupported(): string
    {
        $names = array_values(self::NAMES);
        $last = array_pop($names);
        return sprintf('Unsupported image format (expected %s or %s).', implode(', ', $names), $last);
    }
}
