<?php

declare(strict_types=1);

namespace Papercut\Backend;

interface FontHandle
{
    /** Stable identifier, e.g. sha1 of the font file path. */
    public function id(): string;

    /** Advance width in points of $text at $size, matching exactly what the backend draws. */
    public function advance(string $text, float $size, float $letterSpacing = 0.0): float;

    /** Distance above the baseline in points at $size (positive). */
    public function ascent(float $size): float;

    /** Distance below the baseline in points at $size (positive). */
    public function descent(float $size): float;

    /**
     * True when every non-whitespace character has a glyph. Whitespace and control characters
     * (<= U+0020) are always reported as covered; layout must normalise tabs and control
     * characters before drawing.
     */
    public function covers(string $text): bool;
}
