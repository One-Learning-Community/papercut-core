<?php

declare(strict_types=1);

namespace Papercut\Backend\PhpPdf;

use Papercut\Backend\ImageFormat;

/**
 * Converts formats php-pdf cannot embed (WebP, GIF, BMP, AVIF) to PNG with
 * GD. PNG is lossless and keeps transparency, so the conversion adds no loss
 * of its own. GD is optional: without it these formats are refused with a
 * message that says what to install.
 *
 * @internal
 */
final readonly class GdImageConverter
{
    /** GD's IMG_* bits, spelled out because the constants only exist when GD is loaded. */
    private const array GD_BITS = [
        'image/webp' => 32,
        'image/gif' => 1,
        'image/bmp' => 64,
        'image/avif' => 256,
    ];

    /** $types: GD's imagetypes() bitmask, or null when GD is not available. */
    public function __construct(private ?int $types)
    {
    }

    public static function detect(): self
    {
        return new self(extension_loaded('gd') ? imagetypes() : null);
    }

    public function handles(string $mime): bool
    {
        return isset(self::GD_BITS[$mime]);
    }

    /** @throws \RuntimeException with a sentence for the ImageUnreadable message */
    public function toPng(string $bytes, string $mime): string
    {
        $name = ImageFormat::NAMES[$mime] ?? $mime;
        if ($this->types === null) {
            throw new \RuntimeException(sprintf('%s images need the GD extension (ext-gd).', $name));
        }
        if (($this->types & (self::GD_BITS[$mime] ?? 0)) === 0) {
            throw new \RuntimeException(sprintf('This GD build cannot read %s images.', $name));
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new \RuntimeException(sprintf('Invalid %s image data.', $name));
        }
        // An alpha channel makes php-pdf split it out pixel by pixel, about ten times slower, so keep it only when the source may have one.
        imagealphablending($image, false);
        imagesavealpha($image, self::mayHaveAlpha($bytes, $mime));
        ob_start();
        $ok = imagepng($image);
        $png = (string) ob_get_clean();
        if (!$ok || $png === '') {
            throw new \RuntimeException(sprintf('Could not convert the %s image to PNG.', $name));
        }
        return $png;
    }

    /**
     * Read from the header. Palette images (GIF) keep their transparent
     * colour either way; BMP is treated as opaque; AVIF is not inspected.
     */
    private static function mayHaveAlpha(string $bytes, string $mime): bool
    {
        if ($mime !== 'image/webp') {
            return $mime === 'image/avif';
        }
        return match (substr($bytes, 12, 4)) {
            'VP8 ' => false,
            // Lossless: 0x2F signature, then 14-bit width and height, then the alpha_is_used bit.
            'VP8L' => strlen($bytes) >= 25 && ((unpack('V', $bytes, 21)[1] ?? 0) >> 28 & 1) === 1,
            // Extended: the flags byte's 0x10 bit marks alpha.
            'VP8X' => strlen($bytes) >= 21 && (ord($bytes[20]) & 0x10) !== 0,
            default => true,
        };
    }
}
