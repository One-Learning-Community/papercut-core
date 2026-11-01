<?php

declare(strict_types=1);

namespace Papercut\Paint;

use Papercut\Text\CachedFont;
use Papercut\Value\Color;

/**
 * A table of contents entry's page number, filled in by the Painter once the
 * heading's page is known. Coordinates are relative to the carrying fragment,
 * like PageNumberSlot: $x is the left edge and $width the width of the number
 * box on the entry's last line, $baseline that line's baseline, $leaderStart
 * where the title's last line ends. $boxWidth and $boxHeight cover the whole
 * entry from (0, 0), the area its link takes.
 */
final readonly class TocSlot
{
    public function __construct(
        public string $destination,
        public float $x,
        public float $baseline,
        public float $width,
        public float $leaderStart,
        public bool $leaders,
        public CachedFont $font,
        public float $size,
        public Color $color,
        public float $letterSpacing,
        public float $boxWidth,
        public float $boxHeight,
    ) {
    }
}
