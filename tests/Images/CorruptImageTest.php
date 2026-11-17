<?php

declare(strict_types=1);

namespace Quoin\Tests\Images;

use Quoin\Backend\ImageFormat;
use Quoin\Document;
use Quoin\Exception\ImageUnreadable;
use Quoin\Export\HtmlOptions;
use Quoin\Export\PdfOptions;
use Quoin\Image;
use Quoin\Markup\Markup;
use Quoin\Markup\MarkupOptions;
use Quoin\Page;
use Quoin\Tests\Support\TestImages;
use PHPUnit\Framework\TestCase;

/** Corrupt image bytes are treated like a missing image and never reach a decoder that warns about them. */
final class CorruptImageTest extends TestCase
{
    /** @var list<string> */
    private array $diagnostics = [];

    protected function setUp(): void
    {
        $this->diagnostics = [];
        set_error_handler(function (int $no, string $msg): bool {
            $this->diagnostics[] = $msg;
            return true;
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../fixtures/images/' . $name);
    }

    /** @return array<string, array{string}> */
    public static function corrupt(): array
    {
        $cut = static fn (string $b): string => substr($b, 0, intdiv(strlen($b), 2));
        $noise = static fn (int $seed): string => substr(hash('sha256', (string) $seed, true) . hash('sha256', (string) ($seed + 1), true), 0, 64) . str_repeat("\x07\x91", 60);
        return [
            'garbage with a PNG magic number' => ["\x89PNG\r\n\x1a\n" . $noise(1)],
            'garbage with a JPEG magic number' => ["\xFF\xD8\xFF" . $noise(2)],
            'plain garbage' => ['garbage'],
            'truncated PNG' => [$cut(TestImages::png(200, 100))],
            'truncated JPEG' => [$cut(TestImages::jpeg(200, 100))],
            'truncated WebP' => [$cut(TestImages::webp(200, 100))],
            'header-only GIF' => [substr(TestImages::gif(), 0, 10)],
            'GIF with garbage after the header' => [self::fixture('gif_garbage.gif')],
            'GIF cut in half' => [self::fixture('gif_cut.gif')],
            'WebP with garbage payload' => [self::fixture('webp_garbage.webp')],
            'animated WebP' => [self::fixture('anim.webp')],
            'BMP cut short' => [self::fixture('bmp_cut.bmp')],
            'JPEG with fill bytes before a marker' => [self::fixture('fill.jpg')],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('corrupt')]
    public function test_a_corrupt_resolver_image_is_a_missing_one(string $bytes): void
    {
        $options = new MarkupOptions(images: static fn (string $src): string => $bytes);

        $with = Markup::html('<p><img src="a.png" alt="A chart"></p>', $options);
        self::assertSame('Approximated', $with->notes()[0]->level->name);
        $without = Markup::html('<p><img src="a.png"></p>', $options);
        self::assertSame('Dropped', $without->notes()[0]->level->name);
        self::assertCount(1, $without->notes());

        $doc = Document::make(Page::letter()->body(Markup::html('<p>x<img src="a.png" alt="A chart"></p>', $options)));
        $pdf = [];
        $html = [];
        self::assertNotSame('', $doc->toPdf(new PdfOptions(onWarning: function (string $m) use (&$pdf): void {
            $pdf[] = $m;
        }, cacheDir: false))->bytes());
        self::assertNotSame('', $doc->toHtml(new HtmlOptions(onWarning: function (string $m) use (&$html): void {
            $html[] = $m;
        }))->bytes());
        self::assertCount(1, array_filter($pdf, static fn (string $m): bool => str_contains($m, 'markup <img>')));
        self::assertCount(1, array_filter($html, static fn (string $m): bool => str_contains($m, 'markup <img>')));
        $dropped = Document::make(Page::letter()->body(Markup::html('<p>x<img src="b.png"></p>', $options)));
        self::assertNotSame('', $dropped->toPdf(new PdfOptions(onWarning: static fn (string $m) => null, cacheDir: false))->bytes());
        self::assertSame([], $this->diagnostics);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('corrupt')]
    public function test_the_builder_refuses_corrupt_bytes_without_a_php_diagnostic(string $bytes): void
    {
        $doc = Document::make(Page::letter()->body(Image::bytes($bytes)));
        try {
            $doc->toPdf(new PdfOptions(onWarning: static fn (string $m) => null, cacheDir: false));
            self::fail('Expected ImageUnreadable');
        } catch (ImageUnreadable) {
        }
        self::assertSame([], $this->diagnostics);
    }

    public function test_valid_images_still_pass(): void
    {
        $png = TestImages::png(40, 20);
        $idat = strpos($png, 'IDAT') - 4;
        $n = (unpack("N", $png, $idat) ?: [1 => 0])[1];
        $data = substr($png, $idat + 8, $n);
        $half = intdiv($n, 2);
        $chunk = static fn (string $d): string => pack('N', strlen($d)) . 'IDAT' . $d . pack('N', crc32('IDAT' . $d));
        $multi = substr($png, 0, $idat) . $chunk(substr($data, 0, $half)) . $chunk(substr($data, $half)) . substr($png, $idat + 12 + $n);

        $valid = [
            $png, TestImages::png(10, 10, true), $multi, TestImages::jpeg(), TestImages::webp(), TestImages::webp(40, 20, false, false), TestImages::gif(),
            TestImages::gif(40, 20, true), TestImages::bmp(),
            self::fixture('prog.jpg'), self::fixture('trail.jpg'), self::fixture('noeoi.jpg'), self::fixture('lossy.webp'), self::fixture('vp8x.webp'), self::fixture('anim.gif'),
        ];
        foreach ($valid as $i => $bytes) {
            self::assertTrue(ImageFormat::intact($bytes), 'valid image #' . $i);
            $doc = Document::make(Page::letter()->body(Image::bytes($bytes)));
            self::assertNotSame('', $doc->toPdf(new PdfOptions(onWarning: static fn (string $m) => null, cacheDir: false))->bytes(), 'valid image #' . $i);
        }
        self::assertSame([], $this->diagnostics);
    }

    public function test_a_valid_format_this_gd_cannot_read_is_unsupported_not_corrupt(): void
    {
        if (ImageFormat::gdReads('image/avif')) {
            self::markTestSkipped('this GD build reads AVIF');
        }
        $avif = self::fixture('valid.avif');
        self::assertTrue(ImageFormat::intact($avif));
        try {
            Document::make(Page::letter()->body(Image::bytes($avif)))->toPdf(new PdfOptions(onWarning: static fn (string $m) => null, cacheDir: false));
            self::fail('Expected ImageUnreadable');
        } catch (ImageUnreadable $e) {
            self::assertSame('Cannot read image bytes: This GD build cannot read AVIF images.', $e->getMessage());
        }
        $v = Markup::html('<p><img src="a.avif" alt="x"></p>', new MarkupOptions(images: static fn (string $src): string => $avif));
        self::assertSame('Approximated', $v->notes()[0]->level->name);
        self::assertSame([], $this->diagnostics);
    }
}
