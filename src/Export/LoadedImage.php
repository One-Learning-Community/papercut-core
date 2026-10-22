<?php

declare(strict_types=1);

namespace Papercut\Export;

use Papercut\Backend\Backend;
use Papercut\Backend\ImageFormat;
use Papercut\Exception\ImageUnreadable;
use Papercut\Image;

/** An image the backend accepted, with the bytes and facts HTML needs. */
final readonly class LoadedImage
{
    private function __construct(
        public string $bytes,
        public string $mime,
        public int $widthPx,
        public int $heightPx,
    ) {
    }

    /** @throws ImageUnreadable with the backend's message (the PDF wording) */
    public static function load(Image $image, Backend $backend): self
    {
        ['kind' => $kind, 'value' => $value] = $image->source();
        if ($kind === 'file') {
            $bytes = is_file($value) && is_readable($value) ? file_get_contents($value) : false;
            if ($bytes === false) {
                throw new ImageUnreadable(sprintf('Cannot read image "%s": file not found or not readable', $value));
            }
            // Read first for the exact PDF wording on missing files; the backend then validates by path like PDF does.
            $handle = $backend->loadImageFile($value);
            $unsupported = sprintf('Cannot read image "%s": %s', $value, ImageFormat::unsupported());
        } else {
            $bytes = $value;
            $handle = $backend->loadImageBytes($bytes);
            $unsupported = 'Cannot read image bytes: ' . ImageFormat::unsupported();
        }

        // HTML embeds the original bytes, which browsers display in every format the backend accepts.
        // The null case only fires for non-default backends: the default one already refuses other formats.
        $mime = ImageFormat::sniff($bytes) ?? throw new ImageUnreadable($unsupported);

        return new self($bytes, $mime, $handle->widthPx(), $handle->heightPx());
    }
}
