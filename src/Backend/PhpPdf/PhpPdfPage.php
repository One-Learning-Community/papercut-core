<?php

declare(strict_types=1);

namespace Quoin\Backend\PhpPdf;

use Dskripchenko\PhpPdf\Pdf\Reader\ReaderDocument;
use Quoin\Backend\PdfPageHandle;

/** @internal Part of the PDF writer. Custom backends aren't supported, and it may change in any release. */
final class PhpPdfPage implements PdfPageHandle
{
    /** @param int $index counting from 0 */
    public function __construct(
        private readonly ReaderDocument $document,
        private readonly int $index,
        private readonly float $width,
        private readonly float $height,
        private readonly string $id,
    ) {
    }

    public function document(): ReaderDocument
    {
        return $this->document;
    }

    public function index(): int
    {
        return $this->index;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function widthPt(): float
    {
        return $this->width;
    }

    public function heightPt(): float
    {
        return $this->height;
    }
}
