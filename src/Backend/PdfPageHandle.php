<?php

declare(strict_types=1);

namespace Papercut\Backend;

/** A page of an existing PDF, ready to draw. Its size is the page upright, after any rotation. */
interface PdfPageHandle
{
    public function id(): string;

    public function widthPt(): float;

    public function heightPt(): float;
}
