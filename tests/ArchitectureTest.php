<?php

declare(strict_types=1);

namespace Papercut\Tests;

use PHPUnit\Framework\TestCase;

final class ArchitectureTest extends TestCase
{
    public function test_only_phppdf_backend_imports_dskripchenko(): void
    {
        $src = realpath(__DIR__ . '/../src');
        self::assertIsString($src);
        $offenders = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            if (str_starts_with($path, $src . '/Backend/PhpPdf/')) {
                continue;
            }
            if (stripos((string) file_get_contents($path), 'Dskripchenko') !== false) {
                $offenders[] = substr($path, strlen($src) + 1);
            }
        }
        self::assertSame([], $offenders, 'Only src/Backend/PhpPdf may reference Dskripchenko\\*');
    }

    public function test_tree_and_style_do_not_depend_on_layout_or_backend(): void
    {
        $src = realpath(__DIR__ . '/../src');
        self::assertIsString($src);
        $offenders = [];
        foreach (['Tree', 'Style'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $code = (string) file_get_contents($file->getPathname());
                if (preg_match('/Papercut\\\\(Layout|Backend)\b/', $code) === 1) {
                    $offenders[] = substr($file->getPathname(), strlen($src) + 1);
                }
            }
        }
        self::assertSame([], $offenders, 'src/Tree and src/Style must not reference Papercut\\Layout or Papercut\\Backend');
    }
}
