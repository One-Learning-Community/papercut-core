<?php

declare(strict_types=1);

namespace Papercut\Tests\Backend;

use Papercut\Backend\PhpPdf\PhpPdfBackend;
use Papercut\Backend\PhpPdf\PhpPdfFont;
use Papercut\Exception\FontNotFound;
use PHPUnit\Framework\TestCase;

final class PhpPdfFontTest extends TestCase
{
    private const string INTER = __DIR__ . '/../../resources/fonts/Inter/Inter-Regular.ttf';

    private function font(): PhpPdfFont
    {
        $f = (new PhpPdfBackend())->loadFont(self::INTER);
        self::assertInstanceOf(PhpPdfFont::class, $f);
        return $f;
    }

    public function test_text_state_guard_throws_when_internals_missing(): void
    {
        $o = new class { public float $a = 0.0; };
        PhpPdfBackend::guardTextStateProperties($o, ['a']);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('dskripchenko/php-pdf internals changed');
        PhpPdfBackend::guardTextStateProperties($o, ['a', 'missing']);
    }

    public function test_empty_string_has_zero_advance(): void
    {
        self::assertSame(0.0, $this->font()->advance('', 12));
    }

    public function test_notdef_glyph_counts_as_1000_units(): void
    {
        self::assertSame(10.0, $this->font()->advance('中', 10));
    }

    public function test_whitespace_has_positive_advance(): void
    {
        self::assertGreaterThan(0.0, $this->font()->advance(' ', 12));
    }

    public function test_advance_scales_linearly_with_size(): void
    {
        $f = $this->font();
        self::assertEqualsWithDelta($f->advance('Hello', 10) * 2, $f->advance('Hello', 20), 1e-9);
    }

    public function test_kerning_tightens_av_pair(): void
    {
        $f = $this->font();
        self::assertLessThan($f->advance('A', 100) + $f->advance('V', 100), $f->advance('AV', 100));
    }

    public function test_letter_spacing_disables_kerning_and_adds_per_glyph(): void
    {
        $f = $this->font();
        $unkerned = $f->advance('A', 100) + $f->advance('V', 100);
        self::assertEqualsWithDelta($unkerned + 2 * 3.0, $f->advance('AV', 100, 3.0), 1e-9);
    }

    public function test_metrics(): void
    {
        $f = $this->font();
        self::assertGreaterThan(0.0, $f->ascent(12));
        self::assertGreaterThan(0.0, $f->descent(12));
        self::assertLessThan(12 * 1.5, $f->ascent(12) + $f->descent(12));
    }

    public function test_covers(): void
    {
        $f = $this->font();
        self::assertTrue($f->covers('Café naïve'));
        self::assertFalse($f->covers('漢字'));
        self::assertTrue($f->covers(''));
    }

    public function test_same_path_returns_same_handle(): void
    {
        $b = new PhpPdfBackend();
        self::assertSame($b->loadFont(self::INTER), $b->loadFont(self::INTER));
        self::assertSame($b->loadFont(self::INTER)->id(), (new PhpPdfBackend())->loadFont(self::INTER)->id());
    }

    public function test_missing_font(): void
    {
        $this->expectException(FontNotFound::class);
        (new PhpPdfBackend())->loadFont('/nope/missing.ttf');
    }

    public function test_invalid_font_file(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pc') . '.ttf';
        file_put_contents($tmp, 'not a font');
        try {
            $this->expectException(FontNotFound::class);
            (new PhpPdfBackend())->loadFont($tmp);
        } finally {
            @unlink($tmp);
        }
    }
}
