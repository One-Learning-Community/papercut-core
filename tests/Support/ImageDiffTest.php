<?php

declare(strict_types=1);

namespace Papercut\Tests\Support;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('gd')]
final class ImageDiffTest extends TestCase
{
    /**
     * @param int<1, max> $w
     * @param int<1, max> $h
     * @param array<int, array{int, int, int}> $pixels index = y * w + x, values are [r, g, b]
     */
    private function png(int $w, int $h, array $pixels = [], int $gray = 255): string
    {
        $im = imagecreatetruecolor($w, $h);
        self::assertNotFalse($im);
        imagefill($im, 0, 0, ($gray << 16) | ($gray << 8) | $gray);
        foreach ($pixels as $i => [$r, $g, $b]) {
            imagesetpixel($im, $i % $w, intdiv($i, $w), ($r << 16) | ($g << 8) | $b);
        }
        ob_start();
        imagepng($im);
        return (string) ob_get_clean();
    }

    public function test_identical_images_differ_by_zero(): void
    {
        self::assertSame(0.0, ImageDiff::compare($this->png(10, 10), $this->png(10, 10)));
    }

    public function test_one_changed_pixel_in_10x10_is_one_percent(): void
    {
        self::assertEqualsWithDelta(0.01, ImageDiff::compare($this->png(10, 10), $this->png(10, 10, [33 => [0, 0, 0]])), 1e-9);
    }

    public function test_delta_at_or_below_threshold_is_ignored(): void
    {
        $a = $this->png(10, 10);
        self::assertSame(0.0, ImageDiff::compare($a, $this->png(10, 10, [5 => [223, 255, 255]])));
        self::assertEqualsWithDelta(0.01, ImageDiff::compare($a, $this->png(10, 10, [5 => [222, 255, 255]])), 1e-9);
    }

    public function test_size_mismatch_is_total_difference(): void
    {
        self::assertSame(1.0, ImageDiff::compare($this->png(10, 10), $this->png(10, 11)));
    }

    public function test_diff_image_marks_changed_pixels(): void
    {
        $diff = ImageDiff::diffImage($this->png(4, 4), $this->png(4, 4, [0 => [0, 0, 0]]));
        $im = imagecreatefromstring($diff);
        self::assertNotFalse($im);
        self::assertSame(0xFF0000, imagecolorat($im, 0, 0) & 0xFFFFFF);
        self::assertNotSame(0xFF0000, imagecolorat($im, 1, 1) & 0xFFFFFF);
    }
}
