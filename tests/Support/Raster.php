<?php

declare(strict_types=1);

namespace Papercut\Tests\Support;

use PHPUnit\Framework\Assert;

/** PDFs rasterised at 72 dpi with pdftoppm, so a point is a pixel. */
final class Raster
{
    /** Null when pdftoppm or GD is missing, else the reason to skip. */
    public static function unavailable(): ?string
    {
        exec('command -v pdftoppm 2>/dev/null', $out, $code);
        if ($code !== 0) {
            return 'pdftoppm (poppler) is not installed.';
        }
        return extension_loaded('gd') ? null : 'GD is needed to read the rasterised page.';
    }

    public static function of(string $pdf, string $password = '', int $page = 1): \GdImage
    {
        $dir = sys_get_temp_dir() . '/papercut-raster-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            file_put_contents("$dir/doc.pdf", $pdf);
            $upw = $password === '' ? '' : '-upw ' . escapeshellarg($password) . ' ';
            exec(sprintf('pdftoppm %s-png -r 72 -f %d -l %d -singlefile %s %s 2>&1', $upw, $page, $page, escapeshellarg("$dir/doc.pdf"), escapeshellarg("$dir/page")), $out, $code);
            Assert::assertSame(0, $code, implode("\n", $out));
            $image = imagecreatefrompng("$dir/page.png");
            Assert::assertInstanceOf(\GdImage::class, $image);
            return $image;
        } finally {
            array_map('unlink', glob("$dir/*") ?: []);
            rmdir($dir);
        }
    }

    /** True when the pixel is dark ink, false when it is paper. */
    public static function ink(\GdImage $image, int $x, int $y): bool
    {
        $rgb = imagecolorat($image, $x, $y);

        return ((($rgb >> 16) & 0xFF) + (($rgb >> 8) & 0xFF) + ($rgb & 0xFF)) / 3 < 128;
    }
}
