<?php

declare(strict_types=1);

namespace Papercut\Backend\PhpPdf;

use Dskripchenko\PhpPdf\Image\PdfImage;
use Papercut\Backend\ImageHandle;

/** @internal Part of Papercut's PDF writer. Custom backends aren't supported, and it may change in any release. */
final class PhpPdfImage implements ImageHandle
{
    public function __construct(
        private readonly PdfImage $image,
        private readonly string $id,
    ) {
    }

    public function pdfImage(): PdfImage
    {
        return $this->image;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function widthPx(): int
    {
        return $this->image->widthPx;
    }

    public function heightPx(): int
    {
        return $this->image->heightPx;
    }
}
