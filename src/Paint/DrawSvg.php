<?php

declare(strict_types=1);

namespace Papercut\Paint;

use Papercut\Text\SvgFonts;
use Papercut\Vector\SvgDocument;

/**
 * An SVG drawn so that its view box fills this rectangle; the layout clips it to its box.
 * Its text is set in the fonts $fonts picks.
 */
final readonly class DrawSvg implements Op
{
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public SvgDocument $svg,
        public SvgFonts $fonts,
    ) {
        if (!is_finite($x) || !is_finite($y) || !is_finite($width) || !is_finite($height)) {
            throw new \InvalidArgumentException('DrawSvg received a non-finite number.');
        }
    }
}
