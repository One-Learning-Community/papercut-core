<?php

declare(strict_types=1);

namespace Quoin\Tests\Export;

use Quoin\Backend\DefaultBackend;
use Quoin\Exception\ImageUnreadable;
use Quoin\Export\LoadedImage;
use Quoin\Image;
use Quoin\Tests\Support\TestImages;
use PHPUnit\Framework\TestCase;

final class LoadedImageTest extends TestCase
{
    public function test_png_and_jpeg_report_type_and_size(): void
    {
        $backend = DefaultBackend::make(false);
        $png = LoadedImage::load(Image::bytes(TestImages::png(40, 20)), $backend);
        self::assertSame('image/png', $png->mime);
        self::assertSame([40, 20], [$png->widthPx, $png->heightPx]);

        $jpeg = LoadedImage::load(Image::bytes(TestImages::jpeg(40, 20)), $backend);
        self::assertSame('image/jpeg', $jpeg->mime);
        self::assertSame([40, 20], [$jpeg->widthPx, $jpeg->heightPx]);
    }

    public function test_missing_file_throws(): void
    {
        $this->expectException(ImageUnreadable::class);
        $this->expectExceptionMessage('file not found or not readable');
        LoadedImage::load(Image::file('/nonexistent/x.png'), DefaultBackend::make(false));
    }
}
