<?php

declare(strict_types=1);

namespace Papercut\Backend;

/**
 * A page of an existing PDF, ready to draw. Its size is the page upright, after any rotation.
 *
 * @internal Part of Papercut's PDF writer. Custom backends aren't supported, and it may change in any release.
 */
interface PdfPageHandle
{
    public function id(): string;

    public function widthPt(): float;

    public function heightPt(): float;
}
