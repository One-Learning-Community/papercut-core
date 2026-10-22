<?php

declare(strict_types=1);

namespace Papercut\Backend\PhpPdf;

use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Papercut\Backend\FontHandle;

final class PhpPdfFont implements FontHandle
{
    public function __construct(
        private readonly PdfFont $font,
        private readonly string $id,
    ) {
    }

    public function pdfFont(): PdfFont
    {
        return $this->font;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function advance(string $text, float $size, float $letterSpacing = 0.0): float
    {
        if ($text === '') {
            return 0.0;
        }
        $units = 0;
        $count = 0;
        $prev = null;
        foreach ($this->font->shapedGlyphs($text) as $glyph) {
            $gid = $glyph['gid'];
            // php-pdf writes no /W or /DW entry for .notdef (gid 0), so viewers draw it 1000 units wide.
            $units += $gid === 0 ? 1000 : $this->font->widthOfGlyphPdfUnits($gid);
            if ($letterSpacing === 0.0 && $prev !== null) {
                $units -= $this->font->kerningPdfUnits($prev, $gid);
            }
            $prev = $gid;
            $count++;
        }
        return $units / 1000 * $size + ($letterSpacing !== 0.0 ? $count * $letterSpacing : 0.0);
    }

    public function ascent(float $size): float
    {
        $ttf = $this->font->ttf();
        return $ttf->ascent() / $ttf->unitsPerEm() * $size;
    }

    public function descent(float $size): float
    {
        $ttf = $this->font->ttf();
        return abs($ttf->descent()) / $ttf->unitsPerEm() * $size;
    }

    public function covers(string $text): bool
    {
        return $this->font->supportsText($text);
    }
}
