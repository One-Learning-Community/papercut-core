<?php

declare(strict_types=1);

namespace Papercut\Tests\Backend;

use Papercut\{Document, Page, Text};
use Papercut\Export\PdfOptions;
use Papercut\Text\{FontFace, FontSet};
use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Papercut\Backend\PhpPdf\{KerningCache, PhpPdfBackend};
use Papercut\Text\FontRegistry;
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

final class KerningCacheTest extends TestCase
{
    private const string INTER = __DIR__ . '/../../resources/fonts/Inter/Inter-Regular.ttf';

    private string $dir;
    /** A private copy of Inter, so php-pdf's process-wide font cache has not parsed it yet. */
    private string $font;

    protected function setUp(): void
    {
        $this->dir = (string) realpath(sys_get_temp_dir()) . '/papercut-kern-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $fonts = $this->dir . '-fonts';
        @mkdir($fonts);
        $this->font = $fonts . '/Inter-Regular.ttf';
        copy(self::INTER, $this->font);
    }

    /**
     * Simulates a fresh process for this test's font copies: php-pdf keeps parsed fonts (and
     * their ~30 MB kerning tables) in a static cache. Only the copies are dropped; dropping the
     * shared bundled fonts would make later tests parse them again while old copies are still
     * referenced, which is how this suite once exhausted a 256 MB memory limit.
     */
    private function forgetParsedFonts(): void
    {
        $cache = new \ReflectionProperty(TtfFile::class, 'cache');
        /** @var array<string, TtfFile> $fonts */
        $fonts = $cache->getValue();
        $prefix = (string) realpath(dirname($this->font));
        $cache->setValue(null, array_filter($fonts, static fn (string $key): bool => !str_starts_with($key, $prefix), ARRAY_FILTER_USE_KEY));
        gc_collect_cycles();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        @unlink($this->font);
        @rmdir($this->dir . '-fonts');
        $this->forgetParsedFonts();
    }

    /** @return list<string> */
    private function cacheFiles(): array
    {
        return glob($this->dir . '/kern-*') ?: [];
    }

    public function test_default_dir_is_under_the_system_temp_dir(): void
    {
        $suffix = function_exists('posix_geteuid') ? '-' . posix_geteuid() : '';
        self::assertSame(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'papercut-cache' . $suffix, KerningCache::defaultDir());
    }

    public function test_first_load_writes_the_pair_table_and_measurements_match_an_uncached_font(): void
    {
        $uncached = (new PhpPdfBackend(kerningCacheDir: false))->loadFont(self::INTER);
        $cold = (new PhpPdfBackend(kerningCacheDir: $this->dir))->loadFont($this->font);
        self::assertCount(1, $this->cacheFiles());
        $this->forgetParsedFonts();
        $warm = (new PhpPdfBackend(kerningCacheDir: $this->dir))->loadFont($this->font);
        self::assertCount(1, $this->cacheFiles());
        foreach (['AV', 'To', 'Wavy Tea', 'hello'] as $text) {
            self::assertSame($uncached->advance($text, 12.0), $cold->advance($text, 12.0), $text);
            self::assertSame($uncached->advance($text, 12.0), $warm->advance($text, 12.0), $text);
        }
        self::assertLessThan($uncached->advance('A', 12.0) + $uncached->advance('V', 12.0), $warm->advance('AV', 12.0));
    }

    public function test_a_warm_load_reads_the_pair_table_from_the_cache(): void
    {
        (new PhpPdfBackend(kerningCacheDir: $this->dir))->loadFont($this->font);
        [$file] = $this->cacheFiles();
        // Replace the cached table with one that kerns nothing: a warm load must use it.
        $payload = unserialize((string) file_get_contents($file), ['allowed_classes' => false]);
        self::assertIsArray($payload);
        $payload['pairs'] = [];
        file_put_contents($file, serialize($payload));
        $plain = (new PhpPdfBackend(kerningCacheDir: false))->loadFont(self::INTER);
        $this->forgetParsedFonts();
        $warm = (new PhpPdfBackend(kerningCacheDir: $this->dir))->loadFont($this->font);
        self::assertSame($plain->advance('A', 12.0) + $plain->advance('V', 12.0), $warm->advance('AV', 12.0));
    }

    public function test_a_table_php_pdf_already_parsed_in_this_process_is_kept(): void
    {
        (new PhpPdfBackend(kerningCacheDir: $this->dir))->loadFont($this->font);
        [$file] = $this->cacheFiles();
        file_put_contents($file, serialize(['papercut-kern' => 1, 'pairs' => []]));
        $plain = (new PhpPdfBackend(kerningCacheDir: false))->loadFont(self::INTER);
        $font = (new PhpPdfBackend(kerningCacheDir: $this->dir))->loadFont($this->font);
        self::assertSame($plain->advance('AV', 12.0), $font->advance('AV', 12.0));
    }

    public function test_corrupt_cache_files_are_ignored_and_replaced(): void
    {
        (new PhpPdfBackend(kerningCacheDir: $this->dir))->loadFont($this->font);
        [$file] = $this->cacheFiles();
        file_put_contents($file, 'garbage');
        $plain = (new PhpPdfBackend(kerningCacheDir: false))->loadFont(self::INTER);
        $this->forgetParsedFonts();
        $font = (new PhpPdfBackend(kerningCacheDir: $this->dir))->loadFont($this->font);
        self::assertSame($plain->advance('AV', 12.0), $font->advance('AV', 12.0));
        self::assertNotSame('garbage', file_get_contents($file));
    }

    public function test_the_key_changes_with_the_font_file_mtime(): void
    {
        (new PhpPdfBackend(kerningCacheDir: $this->dir))->loadFont($this->font);
        touch($this->font, time() + 10);
        (new PhpPdfBackend(kerningCacheDir: $this->dir))->loadFont($this->font);
        self::assertCount(2, $this->cacheFiles());
    }

    public function test_an_unwritable_cache_dir_falls_back_silently(): void
    {
        file_put_contents($this->dir, 'not a directory');
        try {
            $plain = (new PhpPdfBackend(kerningCacheDir: false))->loadFont(self::INTER);
            $font = (new PhpPdfBackend(kerningCacheDir: $this->dir . '/sub'))->loadFont($this->font);
            self::assertSame($plain->advance('AV', 12.0), $font->advance('AV', 12.0));
        } finally {
            unlink($this->dir);
        }
    }

    public function test_pdf_options_pass_the_cache_dir_to_the_default_backend(): void
    {
        (new PdfOptions(cacheDir: $this->dir))->backend()->loadFont($this->font);
        self::assertCount(1, $this->cacheFiles());
    }

    public function test_output_is_identical_uncached_cold_and_warm(): void
    {
        $doc = Document::make(Page::a4()->body(Text::make('AVATAR Wavy Tea, To you.')->size(pt(18))->font('Copy')))
            ->createdAt(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $render = function (string|false $dir) use ($doc): string {
            $backend = new PhpPdfBackend(kerningCacheDir: $dir);
            $fonts = new FontSet([new FontFace('Copy', $this->font)]);
            return $doc->toPdf(new PdfOptions(backend: $backend, fonts: $fonts))->bytes();
        };
        $uncached = $render(false);
        $this->forgetParsedFonts();
        $cold = $render($this->dir);
        self::assertCount(1, $this->cacheFiles());
        $this->forgetParsedFonts();
        $warm = $render($this->dir);
        self::assertSame($uncached, $cold);
        self::assertSame($uncached, $warm);
    }

    public function test_a_file_with_non_integer_pairs_is_ignored_and_replaced(): void
    {
        (new PhpPdfBackend(kerningCacheDir: $this->dir))->loadFont($this->font);
        [$file] = $this->cacheFiles();
        file_put_contents($file, serialize(['papercut-kern' => 1, 'pairs' => [36 => [57 => 'x'], 'a' => []]]));
        $plain = (new PhpPdfBackend(kerningCacheDir: false))->loadFont(self::INTER);
        $this->forgetParsedFonts();
        $font = (new PhpPdfBackend(kerningCacheDir: $this->dir))->loadFont($this->font);
        self::assertSame($plain->advance('AV', 12.0), $font->advance('AV', 12.0));
        $payload = unserialize((string) file_get_contents($file), ['allowed_classes' => false]);
        self::assertIsArray($payload);
        self::assertNotSame([36 => [57 => 'x'], 'a' => []], $payload['pairs']);
    }

    public function test_a_group_or_world_writable_cache_dir_is_not_trusted(): void
    {
        (new PhpPdfBackend(kerningCacheDir: $this->dir))->loadFont($this->font);
        [$file] = $this->cacheFiles();
        file_put_contents($file, serialize(['papercut-kern' => 1, 'pairs' => []])); // would disable kerning if read
        chmod($this->dir, 0777);
        $plain = (new PhpPdfBackend(kerningCacheDir: false))->loadFont(self::INTER);
        $this->forgetParsedFonts();
        $font = (new PhpPdfBackend(kerningCacheDir: $this->dir))->loadFont($this->font);
        self::assertSame($plain->advance('AV', 12.0), $font->advance('AV', 12.0));
        self::assertLessThan($plain->advance('A', 12.0) + $plain->advance('V', 12.0), $font->advance('AV', 12.0));
    }

    public function test_a_symlinked_cache_dir_is_not_trusted(): void
    {
        $real = $this->dir . '-real';
        mkdir($real, 0700);
        symlink($real, $this->dir);
        try {
            (new PhpPdfBackend(kerningCacheDir: $this->dir))->loadFont($this->font);
            self::assertSame([], glob($real . '/kern-*.ser') ?: []);
        } finally {
            @unlink($this->dir);
            @rmdir($real);
        }
    }

    public function test_entries_untouched_for_30_days_are_pruned_once_per_process_and_dir(): void
    {
        mkdir($this->dir, 0700);
        $stale = $this->dir . '/kern-stale.ser';
        $recent = $this->dir . '/kern-recent.ser';
        $other = $this->dir . '/not-ours.txt';
        foreach ([$stale, $recent, $other] as $f) {
            file_put_contents($f, 'x');
        }
        touch($stale, time() - 31 * 86400);
        touch($other, time() - 31 * 86400);
        touch($recent, time() - 29 * 86400);
        (new PhpPdfBackend(kerningCacheDir: $this->dir))->loadFont($this->font);
        self::assertFileDoesNotExist($stale);
        self::assertFileExists($recent);
        self::assertFileExists($other);

        file_put_contents($stale, 'x');
        touch($stale, time() - 31 * 86400);
        (new PhpPdfBackend(kerningCacheDir: $this->dir))->loadFont(self::INTER);
        self::assertFileExists($stale); // already pruned this directory in this process
    }
}
