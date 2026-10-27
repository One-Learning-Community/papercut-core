<?php

declare(strict_types=1);

namespace Papercut\Backend\PhpPdf;

use Dskripchenko\PhpPdf\Pdf\Reader\ReaderDocument;
use Papercut\Backend\PdfPageHandle;

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
