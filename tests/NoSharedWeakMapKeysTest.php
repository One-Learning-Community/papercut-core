<?php

declare(strict_types=1);

namespace Papercut\Tests;

use PHPUnit\Framework\TestCase;

/**
 * PHP 8.4 and 8.5 can corrupt the heap when an object that is a key in two or more WeakMaps holding objects or
 * arrays (or refcounted strings) is freed and a garbage collection starts while the maps release their values
 * (php/php-src#20001). Caches that live for one export are SplObjectStorage (strong) instead. A WeakMap may stay
 * only where its values are not refcounted (bool, int, float), or where it is listed below with its reason.
 */
final class NoSharedWeakMapKeysTest extends TestCase
{
    /** Files that may use a WeakMap, by path under packages/, with why. */
    private const array ALLOWED = [
        'core/src/Layout/TableLayouter.php' => 'only flag and token maps with true/int values (warned, in-table, theme tokens)',
        'core/src/Html/HtmlWriter.php' => 'strays holds true values',
        'core/src/Style/StyleResolver.php' => 'endsContainer holds true values',
        'docx/src/Map/Settled.php' => 'holds true values',
        'docx/src/Map/Flow.php' => 'hairlines holds true values',
        'core/src/Layout/TableGrid.php' => 'object-valued, but static and must not leak; Table is a key in no other map that holds objects, arrays or strings',
    ];

    /** Allowed files whose maps may hold objects or arrays. */
    private const array OBJECT_VALUED = [
        'core/src/Layout/TableGrid.php',
    ];

    public function test_weak_maps_are_limited_to_the_listed_files_and_value_kinds(): void
    {
        $packages = realpath(__DIR__ . '/../..');
        self::assertIsString($packages);
        $unlisted = [];
        $objectValued = [];
        foreach (['core', 'docx', 'laravel'] as $package) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$packages/$package/src", \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $rel = substr($file->getPathname(), strlen($packages) + 1);
                $source = (string) file_get_contents($file->getPathname());
                if (!str_contains($source, 'WeakMap')) {
                    continue;
                }
                if (!isset(self::ALLOWED[$rel])) {
                    $unlisted[] = $rel;
                    continue;
                }
                if (in_array($rel, self::OBJECT_VALUED, true)) {
                    continue;
                }
                preg_match_all('/WeakMap<[^,<>]+,\s*([^>]*)>/', $source, $m);
                foreach ($m[1] as $value) {
                    if (!in_array(trim($value), ['true', 'false', 'int', 'bool', 'float'], true)) {
                        $objectValued[] = "$rel: WeakMap value $value";
                    }
                }
                // every declaration needs a typed @var the check above can read
                $declared = preg_match_all('/\\\\WeakMap \$/', $source);
                $typed = preg_match_all('/@var \??\\\\WeakMap<[^,<>]+,/', $source);
                if ($declared > $typed) {
                    $objectValued[] = "$rel: $declared WeakMap declarations but $typed typed @var";
                }
            }
        }
        self::assertSame([], $unlisted, 'A WeakMap in a file not in ALLOWED: use SplObjectStorage for per-export caches (php/php-src#20001)');
        self::assertSame([], $objectValued, 'A WeakMap holding objects or arrays outside OBJECT_VALUED (php/php-src#20001)');
    }

    public function test_every_listed_file_still_uses_a_weak_map(): void
    {
        $packages = realpath(__DIR__ . '/../..');
        self::assertIsString($packages);
        foreach (array_keys(self::ALLOWED) as $rel) {
            self::assertStringContainsString('WeakMap', (string) file_get_contents("$packages/$rel"), "$rel no longer uses a WeakMap: drop it from ALLOWED");
        }
    }
}
