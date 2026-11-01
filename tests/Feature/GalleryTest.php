<?php

declare(strict_types=1);

namespace Papercut\Tests\Feature;

use Papercut\Document;
use Papercut\Site\Gallery;
use Papercut\Tests\Support\PdfInspector;
use PHPUnit\Framework\TestCase;

/** The docs gallery: every manifest entry has a source that renders and an image, and every source is listed. */
final class GalleryTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 4);
    }

    private static function gallery(): Gallery
    {
        require_once self::root() . '/site/gallery/Gallery.php';

        return Gallery::load(self::root() . '/site/gallery/gallery.json');
    }

    /** A source that spills onto a page the image doesn't show would lose content silently. */
    public function test_every_entry_has_a_source_that_renders_exactly_its_pages(): void
    {
        foreach (self::gallery()->entries() as $entry) {
            $document = require $entry['source'];
            self::assertInstanceOf(Document::class, $document, $entry['id']);
            $pdf = $document->toPdf()->bytes();
            self::assertStringStartsWith('%PDF', $pdf, $entry['id']);
            self::assertSame((string) $entry['pages'], PdfInspector::info($pdf)['Pages'] ?? null, $entry['id'] . ' renders a different number of pages than the manifest shows');
        }
    }

    public function test_every_source_is_listed(): void
    {
        $listed = array_column(self::gallery()->entries(), 'source');
        $files = glob(self::root() . '/site/gallery/sources/*.php') ?: [];
        sort($listed);
        sort($files);
        self::assertSame($files, $listed);
    }

    public function test_every_image_exists(): void
    {
        foreach (self::gallery()->entries() as $entry) {
            self::assertFileExists($entry['image'], $entry['id']);
        }
    }

    public function test_ids_are_unique_and_api_links_point_into_the_site(): void
    {
        $gallery = self::gallery();
        $ids = array_column($gallery->entries(), 'id');
        self::assertSame(array_values(array_unique($ids)), $ids);
        foreach ($gallery->tiles() as $tile) {
            self::assertMatchesRegularExpression('#^/(guide|laravel|reference)/[\w-]+\.html(\#[\w-]+)?$#', $tile['api'], $tile['id']);
            $page = preg_replace('#\.html(\#.*)?$#', '.md', $tile['api']);
            self::assertFileExists(self::root() . '/site/src' . $page, $tile['id']);
        }
    }

    /** getopt() stops at the first plain argument, so "card.flat --out=/tmp" would quietly overwrite the committed image. */
    public function test_render_script_rejects_options_after_the_id(): void
    {
        $out = sys_get_temp_dir() . '/papercut-gallery-out-' . bin2hex(random_bytes(4));
        $image = self::root() . '/site/src/.vuepress/public/gallery/card.png';
        $before = (string) file_get_contents($image);
        $cmd = sprintf('cd %s && %s site/gallery/render.php card --out=%s 2>&1 1>/dev/null', escapeshellarg(self::root()), escapeshellarg(PHP_BINARY), escapeshellarg($out));
        exec($cmd, $stderr, $code);
        self::assertSame(1, $code, implode("\n", $stderr));
        self::assertStringContainsString('options go before the image id', implode("\n", $stderr));
        self::assertSame($before, (string) file_get_contents($image));
        self::assertDirectoryDoesNotExist($out);
    }

    public function test_render_script_reports_a_failing_source(): void
    {
        $dir = sys_get_temp_dir() . '/papercut-gallery-' . bin2hex(random_bytes(4));
        mkdir($dir . '/sources', 0777, true);
        mkdir($dir . '/out');
        file_put_contents($dir . '/sources/broken.php', "<?php\nthrow new RuntimeException('boom');\n");
        file_put_contents($dir . '/gallery.json', json_encode(['groups' => [['title' => 'T', 'tiles' => [
            ['id' => 'broken', 'title' => 'Broken', 'api' => '/guide/layout.html', 'alt' => 'x', 'pages' => 1, 'variants' => []],
        ]]]]));
        try {
            $cmd = sprintf(
                'cd %s && %s site/gallery/render.php --manifest=%s --out=%s 2>&1 1>/dev/null',
                escapeshellarg(self::root()),
                escapeshellarg(PHP_BINARY),
                escapeshellarg($dir . '/gallery.json'),
                escapeshellarg($dir . '/out'),
            );
            exec($cmd, $stderr, $code);
            $message = implode("\n", $stderr);
            self::assertSame(1, $code, $message);
            self::assertStringContainsString($dir . '/sources/broken.php', $message);
            self::assertStringContainsString('boom', $message);
            self::assertSame([], glob($dir . '/out/*') ?: []);
        } finally {
            array_map('unlink', glob($dir . '/out/*') ?: []);
            @rmdir($dir . '/out');
            unlink($dir . '/sources/broken.php');
            rmdir($dir . '/sources');
            unlink($dir . '/gallery.json');
            rmdir($dir);
        }
    }
}
