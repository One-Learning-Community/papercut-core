<?php

declare(strict_types=1);

namespace Papercut\Tests\Export;

use Papercut\{Document, Image, OverflowMode, Page, Text};
use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Papercut\Backend\PhpPdf\FontStateCache;
use Papercut\Exception\LayoutOverflow;
use Papercut\Export\{ExportOptions, PdfExporter, PdfOptions};
use Papercut\Style\Theme;
use Papercut\Text\{FontFace, FontSet};
use PHPUnit\Framework\TestCase;

use function Papercut\{mm, pt};

final class PdfExporterTest extends TestCase
{
    private function doc(): Document
    {
        return Document::make(Page::a5()->body(Text::make('Hi')))->createdAt(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
    }

    public function test_to_pdf_is_a_pdf_output(): void
    {
        $out = $this->doc()->toPdf();
        self::assertSame('application/pdf', $out->mime());
        self::assertSame('pdf', $out->extension());
        self::assertStringStartsWith('%PDF-', $out->bytes());
    }

    public function test_export_with_plain_export_options_uses_their_theme(): void
    {
        $doc = $this->doc();
        $themed = $doc->export(new PdfExporter(), new ExportOptions(theme: Theme::default()->with(fontSize: 20.0)));
        self::assertNotSame($doc->export(new PdfExporter())->bytes(), $themed->bytes());
        self::assertSame($doc->toPdf()->bytes(), $doc->export(new PdfExporter())->bytes());
    }

    public function test_from_is_identity_for_pdf_options(): void
    {
        $o = new PdfOptions(overflow: OverflowMode::Clip);
        self::assertSame($o, PdfOptions::from($o));
        self::assertSame(OverflowMode::Throw, PdfOptions::from(new ExportOptions())->overflow());
    }

    public function test_pdf_options_keep_overflow(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $doc = Document::make(Page::a5()->body(Image::bytes((string) $png)->width(mm(10))->height(mm(5000))));
        try {
            $doc->toPdf();
            self::fail('expected LayoutOverflow');
        } catch (LayoutOverflow) {
            self::addToAssertionCount(1);
        }
        $warnings = [];
        $pdf = $doc->toPdf(new PdfOptions(onWarning: function (string $m) use (&$warnings): void {
            $warnings[] = $m;
        }, overflow: OverflowMode::Clip))->bytes();
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertNotSame([], $warnings);
    }

    public function test_cache_dir_controls_where_the_font_cache_is_written(): void
    {
        $base = (string) realpath(sys_get_temp_dir()) . '/papercut-cachedir-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $fontDir = $base . '-fonts';
        $on = $base . '-on';
        mkdir($fontDir);
        // Private font copies: php-pdf parses each path once per process, so earlier tests cannot have warmed them.
        copy(__DIR__ . '/../../resources/fonts/Inter/Inter-Regular.ttf', $fontDir . '/On.ttf');
        copy(__DIR__ . '/../../resources/fonts/Inter/Inter-Regular.ttf', $fontDir . '/Off.ttf');
        $render = static fn (string $family, string $file, string|false $cacheDir): string => Document::make(Page::a5()->body(Text::make('AVATAR Wavy Tea')->font($family)))
            ->createdAt(new \DateTimeImmutable('2026-01-01T00:00:00Z'))
            ->toPdf(new PdfOptions(fonts: new FontSet([new FontFace($family, $fontDir . '/' . $file)]), cacheDir: $cacheDir))
            ->bytes();
        try {
            $before = glob(FontStateCache::defaultDir() . '/font-*') ?: [];
            $render('Off', 'Off.ttf', false);
            self::assertSame($before, glob(FontStateCache::defaultDir() . '/font-*') ?: [], 'cacheDir: false must not fall back to the default directory');
            $render('On', 'On.ttf', $on);
            self::assertNotSame([], glob($on . '/font-*'));
        } finally {
            foreach ([$on] as $d) {
                array_map('unlink', glob($d . '/*') ?: []);
                @rmdir($d);
            }
            array_map('unlink', glob($fontDir . '/*') ?: []);
            @rmdir($fontDir);
            $cache = new \ReflectionProperty(TtfFile::class, 'cache');
            /** @var array<string, TtfFile> $parsed */
            $parsed = $cache->getValue();
            $cache->setValue(null, array_filter($parsed, static fn (string $key): bool => !str_starts_with($key, $fontDir), ARRAY_FILTER_USE_KEY));
            gc_collect_cycles();
        }
    }

    public function test_from_copies_every_shared_field(): void
    {
        $theme = Theme::default()->with(fontSize: 20.0);
        $fonts = new FontSet(fallbacks: ['Inter']);
        $onWarning = static function (string $m): void {
        };
        $from = PdfOptions::from(new ExportOptions($theme, $fonts, $onWarning, true));
        self::assertSame($theme, $from->theme());
        self::assertSame($fonts, $from->fonts());
        self::assertSame($onWarning, $from->onWarning());
        self::assertTrue($from->strict());
        self::assertSame(OverflowMode::Throw, $from->overflow());
        self::assertNull($from->cacheDir());
    }
}
