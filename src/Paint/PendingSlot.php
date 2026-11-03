<?php

declare(strict_types=1);

namespace Papercut\Paint;

/**
 * A page number or table of contents entry painted before the document's page count and headings' pages are known,
 * at the offset of the fragment that holds it. Painter::resolve() replaces it; a backend never sees one.
 *
 * @internal Part of the display list between layout and the PDF writer. It may change in any release.
 */
final readonly class PendingSlot implements Op
{
    public function __construct(
        public PageNumberSlot|TocSlot $slot,
        public float $x,
        public float $y,
    ) {
    }
}
