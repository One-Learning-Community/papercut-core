<?php

declare(strict_types=1);

namespace Papercut\Tests\Backend;

use Papercut\Backend\PhpPdf\{GdImageConverter, PhpPdfBackend, PhpPdfImage};
use Papercut\Document;
use Papercut\Exception\ImageUnreadable;
use Papercut\Export\{LoadedImage, PdfExporter, PdfOptions};
use Papercut\Image;
use Papercut\Page;
use Papercut\Tests\Support\TestImages;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('gd')]
final class GdImagesTest extends TestCase
{
    private function load(string $bytes, ?PhpPdfBackend $backend = null): PhpPdfImage
    {
        $image = ($backend ?? new PhpPdfBackend(false))->loadImageBytes($bytes);
        self::assertInstanceOf(PhpPdfImage::class, $image);
        return $image;
    }

    private function refusal(string $bytes, ?PhpPdfBackend $backend = null): string
    {
        try {
            ($backend ?? new PhpPdfBackend(false))->loadImageBytes($bytes);
        } catch (ImageUnreadable $e) {
            return $e->getMessage();
        }
        self::fail('expected ImageUnreadable');
    }

    public function test_webp_gif_and_bmp_load_with_their_size(): void
    {
        foreach (['webp' => TestImages::webp(40, 20), 'gif' => TestImages::gif(30, 10), 'bmp' => TestImages::bmp(12, 34)] as $name => $bytes) {
            $image = $this->load($bytes);
            self::assertSame(
                ['webp' => [40, 20], 'gif' => [30, 10], 'bmp' => [12, 34]][$name],
                [$image->widthPx(), $image->heightPx()],
                $name,
            );
        }
    }

    public function test_converted_images_are_lossless_and_keep_alpha(): void
    {
        self::assertNull($this->load(TestImages::webp())->pdfImage()->alphaData);
        self::assertNotNull($this->load(TestImages::webp(alpha: true))->pdfImage()->alphaData);
        self::assertNotNull($this->load(TestImages::gif(transparent: true))->pdfImage()->alphaData);
        self::assertSame('/FlateDecode', $this->load(TestImages::webp())->pdfImage()->filter);
    }

    public function test_opaque_images_convert_without_an_alpha_channel(): void
    {
        // PNG colour type (IHDR byte 25): 2 = RGB, 6 = RGBA. An alpha channel php-pdf must split out costs ~10x.
        $type = static fn (string $bytes, string $mime): int => ord(GdImageConverter::detect()->toPng($bytes, $mime)[25]);
        self::assertSame(2, $type(TestImages::webp(), 'image/webp'), 'lossless');
        self::assertSame(2, $type(TestImages::webp(lossless: false), 'image/webp'), 'lossy');
        self::assertSame(6, $type(TestImages::webp(alpha: true), 'image/webp'), 'lossless with alpha');
        self::assertSame(6, $type(TestImages::webp(alpha: true, lossless: false), 'image/webp'), 'lossy with alpha');
        self::assertSame(2, $type(TestImages::bmp(), 'image/bmp'), 'bmp');
    }

    public function test_a_webp_file_renders_into_a_pdf(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pc') . '.webp';
        file_put_contents($path, TestImages::webp(200, 100, alpha: true));
        try {
            $doc = Document::make(Page::a4()->body(Image::file($path)));
            $pdf = (new PdfExporter())->export($doc, new PdfOptions(cacheDir: false))->bytes();
            self::assertStringStartsWith('%PDF-', $pdf);
            self::assertStringContainsString('/SMask', $pdf);
        } finally {
            @unlink($path);
        }
    }

    public function test_corrupt_data_names_the_format(): void
    {
        self::assertSame('Cannot read image bytes: Invalid WebP image data.', $this->refusal('RIFF' . pack('V', 20) . 'WEBPVP8 ' . str_repeat("\0", 12)));
        self::assertSame('Cannot read image bytes: Invalid GIF image data.', $this->refusal('GIF89a....'));
    }

    public function test_unknown_formats_list_what_is_accepted(): void
    {
        self::assertSame(
            'Cannot read image bytes: Unsupported image format (expected PNG, JPEG, WebP, GIF, BMP or AVIF).',
            $this->refusal("II*\0" . str_repeat("\0", 16)),
        );
    }

    public function test_without_gd_the_error_says_what_to_install(): void
    {
        $backend = new PhpPdfBackend(false, new GdImageConverter(null));
        self::assertSame('Cannot read image bytes: WebP images need the GD extension (ext-gd).', $this->refusal(TestImages::webp(), $backend));
        // PNG and JPEG never need GD.
        self::assertSame(40, $this->load(TestImages::png(), $backend)->widthPx());
    }

    public function test_a_gd_build_without_the_format_says_so(): void
    {
        $backend = new PhpPdfBackend(false, new GdImageConverter(0));
        self::assertSame('Cannot read image bytes: This GD build cannot read WebP images.', $this->refusal(TestImages::webp(), $backend));
    }

    public function test_file_errors_name_the_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pc') . '.gif';
        file_put_contents($path, 'GIF89a....');
        try {
            (new PhpPdfBackend(false))->loadImageFile($path);
            self::fail('expected ImageUnreadable');
        } catch (ImageUnreadable $e) {
            self::assertSame(sprintf('Cannot read image "%s": Invalid GIF image data.', $path), $e->getMessage());
        } finally {
            @unlink($path);
        }
    }

    public function test_html_keeps_the_original_bytes_and_type(): void
    {
        $backend = new PhpPdfBackend(false);
        foreach (['image/webp' => TestImages::webp(), 'image/gif' => TestImages::gif(), 'image/bmp' => TestImages::bmp()] as $mime => $bytes) {
            $loaded = LoadedImage::load(Image::bytes($bytes), $backend);
            self::assertSame($mime, $loaded->mime);
            self::assertSame($bytes, $loaded->bytes);
            self::assertSame([40, 20], [$loaded->widthPx, $loaded->heightPx]);
        }
    }
}
