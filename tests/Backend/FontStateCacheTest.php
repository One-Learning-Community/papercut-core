<?php

declare(strict_types=1);

namespace Papercut\Tests\Backend;

use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Papercut\{Document, Page, Text};
use Papercut\Backend\PhpPdf\{FontStateCache, PhpPdfBackend};
use Papercut\Export\PdfOptions;
use Papercut\Text\{FontFace, FontSet};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

final class FontStateCacheTest extends TestCase
{
    private const string INTER = __DIR__ . '/../../resources/fonts/Inter/Inter-Regular.ttf';

    private string $dir;
    /** A private copy of Inter, so no earlier test has loaded it in this process. */
    private string $font;

    protected function setUp(): void
    {
        $this->dir = (string) realpath(sys_get_temp_dir()) . '/papercut-font-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '-fonts');
        $this->font = $this->dir . '-fonts/Inter-Regular.ttf';
        copy(self::INTER, $this->font);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        @unlink($this->font);
        @rmdir($this->dir . '-fonts');
        $this->newProcess();
    }

    /** What a new request starts with: no font parsed or restored yet. */
    private function newProcess(): void
    {
        FontStateCache::forget();
        TtfFile::forgetCache();
    }

    /** @return list<string> */
    private function entries(): array
    {
        return glob($this->dir . '/font-*') ?: [];
    }

    /** @return array<string, mixed> */
    private function readEntry(string $file): array
    {
        $payload = str_ends_with($file, '.php') ? include $file : unserialize((string) file_get_contents($file), ['allowed_classes' => false]);
        self::assertIsArray($payload);
        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function writeEntry(string $file, array $payload): void
    {
        file_put_contents($file, str_ends_with($file, '.php') ? '<?php return ' . var_export($payload, true) . ";\n" : serialize($payload));
    }

    /** @return array<string, array{string}> */
    public static function formats(): array
    {
        return ['serialized file' => [FontStateCache::SERIALIZE], 'PHP file' => [FontStateCache::PHP]];
    }

    public function test_default_dir_is_under_the_system_temp_dir(): void
    {
        $suffix = function_exists('posix_geteuid') ? '-' . posix_geteuid() : '';
        self::assertSame(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'papercut-cache' . $suffix, FontStateCache::defaultDir());
    }

    public function test_an_unknown_format_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FontStateCache($this->dir, 'json');
    }

    #[DataProvider('formats')]
    public function test_a_later_process_restores_the_font_from_its_entry(string $format): void
    {
        $parsed = (new FontStateCache($this->dir, $format))->load($this->font);
        $entries = $this->entries();
        self::assertCount(1, $entries);
        self::assertStringEndsWith('.' . $format, $entries[0]);
        // Rename the font in the entry: only a restore from it can return that name.
        $payload = $this->readEntry($entries[0]);
        self::assertIsArray($payload['state']);
        $payload['state']['postScriptName'] = 'Restored-Inter';
        $this->writeEntry($entries[0], $payload);
        $this->newProcess();

        $restored = (new FontStateCache($this->dir, $format))->load($this->font);

        self::assertSame('Restored-Inter', $restored->postScriptName());
        self::assertSame($parsed->unitsPerEm(), $restored->unitsPerEm());
        $a = $parsed->glyphIdForChar(0x41);
        $v = $parsed->glyphIdForChar(0x56);
        self::assertSame($parsed->advanceWidth($a), $restored->advanceWidth($a));
        self::assertSame($parsed->kerningTable()?->lookup($a, $v), $restored->kerningTable()?->lookup($a, $v));
    }

    public function test_a_font_is_read_from_disk_once_per_process(): void
    {
        $cache = new FontStateCache($this->dir);
        $first = $cache->load($this->font);
        array_map('unlink', $this->entries());

        self::assertSame($first, (new FontStateCache($this->dir))->load($this->font));
        self::assertSame([], $this->entries());
    }

    #[DataProvider('formats')]
    public function test_output_is_identical_uncached_cold_and_warm(string $format): void
    {
        $doc = Document::make(Page::a4()->body(Text::make('AVATAR Wavy Tea, To you — Zoë.')->size(pt(18))->font('Copy')))
            ->createdAt(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $render = function (string|false $dir) use ($doc, $format): string {
            $backend = new PhpPdfBackend(cacheDir: $dir, fontCacheFormat: $format);
            return $doc->toPdf(new PdfOptions(backend: $backend, fonts: new FontSet([new FontFace('Copy', $this->font)])))->bytes();
        };
        $uncached = $render(false);
        $this->newProcess();
        $cold = $render($this->dir);
        self::assertCount(1, $this->entries());
        $this->newProcess();
        $warm = $render($this->dir);

        self::assertSame($uncached, $cold);
        self::assertSame($uncached, $warm);
    }

    public function test_a_changed_font_file_gets_a_new_entry(): void
    {
        (new FontStateCache($this->dir))->load($this->font);
        touch($this->font, time() + 10);
        $this->newProcess();

        (new FontStateCache($this->dir))->load($this->font);

        self::assertCount(2, $this->entries());
    }

    #[DataProvider('formats')]
    public function test_a_damaged_entry_is_parsed_again_and_replaced(string $format): void
    {
        (new FontStateCache($this->dir, $format))->load($this->font);
        [$file] = $this->entries();
        file_put_contents($file, $format === FontStateCache::PHP ? '<?php return 42;' : 'garbage');
        $this->newProcess();

        $font = (new FontStateCache($this->dir, $format))->load($this->font);

        self::assertSame(2048, $font->unitsPerEm());
        self::assertIsArray($this->readEntry($file)['state'] ?? null);
    }

    public function test_a_state_php_pdf_refuses_is_parsed_again_and_replaced(): void
    {
        (new FontStateCache($this->dir))->load($this->font);
        [$file] = $this->entries();
        $payload = $this->readEntry($file);
        self::assertIsArray($payload['state']);
        $payload['state']['format'] = -1; // as written by a php-pdf with another state layout
        $this->writeEntry($file, $payload);
        $this->newProcess();

        $font = (new FontStateCache($this->dir))->load($this->font);

        self::assertSame('Inter-Regular', $font->postScriptName());
        $state = $this->readEntry($file)['state'];
        self::assertIsArray($state);
        self::assertSame(TtfFile::STATE_FORMAT, $state['format']);
    }

    public function test_a_directory_others_can_write_to_is_never_read_or_written(): void
    {
        (new FontStateCache($this->dir))->load($this->font);
        [$file] = $this->entries();
        $payload = $this->readEntry($file);
        self::assertIsArray($payload['state']);
        $payload['state']['postScriptName'] = 'Planted';
        $this->writeEntry($file, $payload);
        chmod($this->dir, 0777);
        $this->newProcess();

        $font = (new FontStateCache($this->dir))->load($this->font);

        self::assertSame('Inter-Regular', $font->postScriptName());
        self::assertSame('Planted', $this->readEntry($file)['state']['postScriptName'] ?? null, 'never rewritten either');
    }

    public function test_a_php_entry_others_can_write_to_is_never_included(): void
    {
        (new FontStateCache($this->dir, FontStateCache::PHP))->load($this->font);
        [$file] = $this->entries();
        $payload = $this->readEntry($file);
        self::assertIsArray($payload['state']);
        $payload['state']['postScriptName'] = 'Planted';
        $this->writeEntry($file, $payload);
        chmod($file, 0666);
        $this->newProcess();

        $font = (new FontStateCache($this->dir, FontStateCache::PHP))->load($this->font);

        self::assertSame('Inter-Regular', $font->postScriptName());
    }

    public function test_a_symlinked_directory_is_never_used(): void
    {
        $real = $this->dir . '-real';
        mkdir($real, 0700);
        symlink($real, $this->dir);
        try {
            (new FontStateCache($this->dir))->load($this->font);
            self::assertSame([], glob($real . '/font-*') ?: []);
        } finally {
            @unlink($this->dir);
            @rmdir($real);
        }
    }

    public function test_an_unusable_directory_falls_back_to_parsing(): void
    {
        file_put_contents($this->dir, 'not a directory');
        try {
            $font = (new FontStateCache($this->dir . '/sub'))->load($this->font);
            self::assertSame(2048, $font->unitsPerEm());
        } finally {
            unlink($this->dir);
        }
    }

    public function test_entries_untouched_for_30_days_are_pruned_once_per_process_and_directory(): void
    {
        mkdir($this->dir, 0700);
        $stale = $this->dir . '/font-stale.ser';
        $recent = $this->dir . '/font-recent.php';
        $other = $this->dir . '/not-ours.txt';
        foreach ([$stale, $recent, $other] as $f) {
            file_put_contents($f, 'x');
        }
        touch($stale, time() - 31 * 86400);
        touch($other, time() - 31 * 86400);
        touch($recent, time() - 29 * 86400);

        (new FontStateCache($this->dir))->load($this->font);

        self::assertFileDoesNotExist($stale);
        self::assertFileExists($recent);
        self::assertFileExists($other);

        file_put_contents($stale, 'x');
        touch($stale, time() - 31 * 86400);
        $this->newProcess(); // a font not in memory yet, so the second load reaches the disk
        (new FontStateCache($this->dir))->load($this->font);
        self::assertFileExists($stale, 'this directory was already pruned in this process');
    }

    public function test_an_entry_in_use_is_kept_clear_of_pruning(): void
    {
        (new FontStateCache($this->dir))->load($this->font);
        [$file] = $this->entries();
        touch($file, time() - 2 * 86400);
        $this->newProcess();

        (new FontStateCache($this->dir))->load($this->font);

        clearstatcache(true, $file);
        self::assertGreaterThan(time() - 60, (int) filemtime($file));
    }

    public function test_pdf_options_pass_the_cache_dir_to_the_default_backend(): void
    {
        (new PdfOptions(cacheDir: $this->dir))->backend()->loadFont($this->font);

        self::assertCount(1, $this->entries());
    }
}
