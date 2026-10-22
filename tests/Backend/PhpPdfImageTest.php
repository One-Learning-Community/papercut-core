<?php

declare(strict_types=1);

namespace Papercut\Tests\Backend;

use Papercut\Backend\PhpPdf\PhpPdfBackend;
use Papercut\Exception\ImageUnreadable;
use Papercut\Tests\Support\TestImages;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('gd')]
final class PhpPdfImageTest extends TestCase
{
    /**
     * @param int<1, max> $w
     * @param int<1, max> $h
     */
    public static function jpegBytes(int $w = 30, int $h = 60): string
    {
        $im = imagecreatetruecolor($w, $h) ?: throw new \RuntimeException('gd failure');
        ob_start();
        imagejpeg($im);
        return (string) ob_get_clean();
    }

    public function test_png_and_jpeg_dimensions(): void
    {
        $b = new PhpPdfBackend();
        $png = $b->loadImageBytes(TestImages::png(40, 20, alpha: true));
        self::assertSame([40, 20], [$png->widthPx(), $png->heightPx()]);
        $jpg = $b->loadImageBytes(self::jpegBytes(30, 60));
        self::assertSame([30, 60], [$jpg->widthPx(), $jpg->heightPx()]);
    }

    public function test_load_file(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pc') . '.png';
        file_put_contents($tmp, TestImages::png());
        try {
            self::assertSame(40, (new PhpPdfBackend())->loadImageFile($tmp)->widthPx());
        } finally {
            @unlink($tmp);
        }
    }

    public function test_unreadable_file(): void
    {
        $this->expectException(ImageUnreadable::class);
        (new PhpPdfBackend())->loadImageFile('/nope/missing.png');
    }

    public function test_unsupported_bytes(): void
    {
        $this->expectException(ImageUnreadable::class);
        (new PhpPdfBackend())->loadImageBytes('GIF89a...');
    }
}
