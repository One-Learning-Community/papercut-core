<?php

declare(strict_types=1);

namespace Papercut\Tests\Visual;

use Papercut\Document;
use Papercut\Tests\Support\ImageDiff;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * Renders each fixture, rasterises every page with pdftoppm at 72 dpi and compares it with the committed
 * snapshot. Poppler versions rasterise text slightly differently, so the comparison tolerates 0.5% of
 * pixels differing by more than 32 per channel.
 *
 * Refresh with: UPDATE_SNAPSHOTS=1 php -d xdebug.mode=off vendor/bin/phpunit tests/Visual
 */
#[RequiresPhpExtension('gd')]
final class SnapshotTest extends TestCase
{
    private const TOLERANCE = 0.005;

    /** @return iterable<string, array{string}> */
    public static function fixtures(): iterable
    {
        $files = glob(__DIR__ . '/fixtures/*.php') ?: [];
        sort($files);
        foreach ($files as $file) {
            yield basename($file, '.php') => [basename($file, '.php')];
        }
    }

    protected function setUp(): void
    {
        exec('command -v pdftoppm 2>/dev/null', $out, $code);
        if ($code !== 0) {
            self::markTestSkipped('pdftoppm (poppler) is not installed.');
        }
    }

    #[DataProvider('fixtures')]
    public function test_fixture_matches_snapshots(string $name): void
    {
        $document = require __DIR__ . "/fixtures/$name.php";
        self::assertInstanceOf(Document::class, $document);
        $pages = $this->rasterise($document->toPdf()->bytes());
        self::assertNotSame([], $pages, 'pdftoppm produced no pages');

        $snapshotDir = __DIR__ . '/snapshots';
        $update = getenv('UPDATE_SNAPSHOTS') === '1';
        if ($update) {
            @mkdir($snapshotDir, 0777, true);
            foreach (glob("$snapshotDir/$name-[0-9]*.png") ?: [] as $stale) {
                unlink($stale);
            }
        }

        $failures = [];
        foreach ($pages as $index => $actual) {
            $snapshot = sprintf('%s/%s-%d.png', $snapshotDir, $name, $index + 1);
            if ($update) {
                file_put_contents($snapshot, $actual);
                continue;
            }
            if (!is_file($snapshot)) {
                $failures[] = "Missing snapshot $snapshot. Review the output, then create it with: UPDATE_SNAPSHOTS=1 php -d xdebug.mode=off vendor/bin/phpunit tests/Visual";
                continue;
            }
            $expected = (string) file_get_contents($snapshot);
            $ratio = ImageDiff::compare($expected, $actual);
            if ($ratio > self::TOLERANCE) {
                $failures[] = $this->saveFailure("$name-" . ($index + 1), $expected, $actual, $ratio);
            }
        }
        if (!$update && count(glob("$snapshotDir/$name-[0-9]*.png") ?: []) > count($pages)) {
            $failures[] = "$name rendered " . count($pages) . ' page(s) but more snapshots exist; delete the stale ones or refresh with UPDATE_SNAPSHOTS=1.';
        }

        self::assertSame([], $failures, implode("\n", $failures));
    }

    private function saveFailure(string $label, string $expected, string $actual, float $ratio): string
    {
        $dir = __DIR__ . '/output';
        @mkdir($dir, 0777, true);
        file_put_contents("$dir/$label.actual.png", $actual);
        file_put_contents("$dir/$label.diff.png", ImageDiff::diffImage($expected, $actual));
        return sprintf(
            '%s differs from its snapshot in %.2f%% of pixels (limit %.1f%%). Actual: %s  Diff: %s',
            $label,
            $ratio * 100,
            self::TOLERANCE * 100,
            "$dir/$label.actual.png",
            "$dir/$label.diff.png",
        );
    }

    /** @return list<string> PNG bytes, one per page */
    private function rasterise(string $pdf): array
    {
        $dir = sys_get_temp_dir() . '/papercut-visual-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            file_put_contents("$dir/doc.pdf", $pdf);
            exec(sprintf('pdftoppm -png -r 72 %s %s 2>&1', escapeshellarg("$dir/doc.pdf"), escapeshellarg("$dir/page")), $out, $code);
            self::assertSame(0, $code, implode("\n", $out));
            $files = glob("$dir/page-*.png") ?: [];
            natsort($files);
            return array_map(static fn (string $f): string => (string) file_get_contents($f), array_values($files));
        } finally {
            array_map('unlink', glob("$dir/*") ?: []);
            @rmdir($dir);
        }
    }
}
