<?php

declare(strict_types=1);

namespace Papercut\Paint;

use Papercut\Backend\PdfPageHandle;

/**
 * A page of another PDF, drawn to fill this rectangle.
 *
 * @internal Part of the display list between layout and the PDF writer. It may change in any release.
 */
final readonly class DrawPdfPage implements Op
{
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public PdfPageHandle $page,
    ) {
        if (!is_finite($x) || !is_finite($y) || !is_finite($width) || !is_finite($height)) {
            throw new \InvalidArgumentException('DrawPdfPage received a non-finite number.');
        }
    }
}
