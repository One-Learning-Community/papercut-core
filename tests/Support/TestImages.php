<?php

declare(strict_types=1);

namespace Papercut\Tests\Support;

final class TestImages
{
    /**
     * @param int<1, max> $w
     * @param int<1, max> $h
     */
    public static function png(int $w = 40, int $h = 20, bool $alpha = false): string
    {
        $im = imagecreatetruecolor($w, $h) ?: throw new \RuntimeException('gd failure');
        if ($alpha) {
            imagesavealpha($im, true);
            imagefill($im, 0, 0, (int) imagecolorallocatealpha($im, 255, 0, 0, 64));
        } else {
            imagefill($im, 0, 0, (int) imagecolorallocate($im, 30, 90, 200));
        }
        ob_start();
        imagepng($im);
        return (string) ob_get_clean();
    }

    /**
     * @param int<1, max> $w
     * @param int<1, max> $h
     */
    public static function jpeg(int $w = 40, int $h = 20): string
    {
        $im = imagecreatetruecolor($w, $h) ?: throw new \RuntimeException('gd failure');
        imagefill($im, 0, 0, (int) imagecolorallocate($im, 30, 90, 200));
        ob_start();
        imagejpeg($im);
        return (string) ob_get_clean();
    }

    /**
     * @param int<1, max> $w
     * @param int<1, max> $h
     */
    public static function webp(int $w = 40, int $h = 20, bool $alpha = false, bool $lossless = true): string
    {
        $im = self::filled($w, $h, $alpha);
        ob_start();
        imagewebp($im, null, $lossless ? IMG_WEBP_LOSSLESS : 80);
        return (string) ob_get_clean();
    }

    /**
     * A palette GIF; with $transparent its fill colour is the transparent index.
     *
     * @param int<1, max> $w
     * @param int<1, max> $h
     */
    public static function gif(int $w = 40, int $h = 20, bool $transparent = false): string
    {
        $im = imagecreate($w, $h) ?: throw new \RuntimeException('gd failure');
        $fill = (int) imagecolorallocate($im, 30, 90, 200);
        if ($transparent) {
            imagecolortransparent($im, $fill);
        }
        ob_start();
        imagegif($im);
        return (string) ob_get_clean();
    }

    /**
     * @param int<1, max> $w
     * @param int<1, max> $h
     */
    public static function bmp(int $w = 40, int $h = 20): string
    {
        $im = self::filled($w, $h, false);
        ob_start();
        imagebmp($im);
        return (string) ob_get_clean();
    }

    /**
     * @param int<1, max> $w
     * @param int<1, max> $h
     */
    private static function filled(int $w, int $h, bool $alpha): \GdImage
    {
        $im = imagecreatetruecolor($w, $h) ?: throw new \RuntimeException('gd failure');
        if ($alpha) {
            imagealphablending($im, false);
            imagesavealpha($im, true);
            imagefill($im, 0, 0, (int) imagecolorallocatealpha($im, 255, 0, 0, 64));
        } else {
            imagefill($im, 0, 0, (int) imagecolorallocate($im, 30, 90, 200));
        }
        return $im;
    }
}
