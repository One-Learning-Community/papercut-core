<?php

declare(strict_types=1);

namespace Papercut\Tests\Support;

/** Pixel comparison of two PNGs (GD). Test-only. */
final class ImageDiff
{
    /**
     * Fraction of pixels whose largest per-channel delta exceeds $threshold.
     * Different dimensions count as a total difference (1.0).
     */
    public static function compare(string $pngA, string $pngB, int $threshold = 32): float
    {
        [$a, $b] = [self::load($pngA), self::load($pngB)];
        [$w, $h] = [imagesx($a), imagesy($a)];
        if ($w !== imagesx($b) || $h !== imagesy($b)) {
            return 1.0;
        }
        $changed = 0;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if (self::delta(self::pixel($a, $x, $y), self::pixel($b, $x, $y)) > $threshold) {
                    $changed++;
                }
            }
        }
        return $changed / ($w * $h);
    }

    /** PNG of the expected image, faded, with pixels that differ at all painted red. */
    public static function diffImage(string $pngA, string $pngB, int $threshold = 32): string
    {
        [$a, $b] = [self::load($pngA), self::load($pngB)];
        $w = max(imagesx($a), imagesx($b));
        $h = max(imagesy($a), imagesy($b));
        $out = imagecreatetruecolor($w, $h);
        if ($out === false) {
            throw new \RuntimeException('GD could not allocate the diff image');
        }
        $red = 0xFF0000;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $inA = $x < imagesx($a) && $y < imagesy($a);
                $inB = $x < imagesx($b) && $y < imagesy($b);
                if (!$inA || !$inB || self::delta(self::pixel($a, $x, $y), self::pixel($b, $x, $y)) > $threshold) {
                    imagesetpixel($out, $x, $y, $red);
                    continue;
                }
                $gray = (int) (255 - (255 - (self::pixel($a, $x, $y) & 0xFF)) * 0.25);
                imagesetpixel($out, $x, $y, ($gray << 16) | ($gray << 8) | $gray);
            }
        }
        ob_start();
        imagepng($out);
        return (string) ob_get_clean();
    }

    private static function pixel(\GdImage $im, int $x, int $y): int
    {
        $p = imagecolorat($im, $x, $y);
        return $p === false ? throw new \RuntimeException("Pixel $x,$y is outside the image") : $p;
    }

    private static function delta(int $p, int $q): int
    {
        return max(
            abs((($p >> 16) & 0xFF) - (($q >> 16) & 0xFF)),
            abs((($p >> 8) & 0xFF) - (($q >> 8) & 0xFF)),
            abs(($p & 0xFF) - ($q & 0xFF)),
        );
    }

    private static function load(string $png): \GdImage
    {
        $im = imagecreatefromstring($png);
        if ($im === false) {
            throw new \InvalidArgumentException('Not a decodable image');
        }
        if (!imageistruecolor($im)) {
            imagepalettetotruecolor($im);
        }
        return $im;
    }
}
