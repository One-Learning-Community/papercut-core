<?php

declare(strict_types=1);

namespace Papercut\Tests\Text;

use PHPUnit\Framework\TestCase;

final class BundledFontsTest extends TestCase
{
    public function test_inter_faces_and_license_are_bundled(): void
    {
        $dir = __DIR__ . '/../../resources/fonts/Inter';
        foreach (['Regular', 'Italic', 'Bold', 'BoldItalic'] as $face) {
            $path = "$dir/Inter-$face.ttf";
            self::assertFileExists($path);
            // TrueType outlines (glyf), not CFF: sfnt version 0x00010000
            self::assertSame("\x00\x01\x00\x00", (string) file_get_contents($path, length: 4));
        }
        self::assertStringContainsString('SIL OPEN FONT LICENSE', strtoupper((string) file_get_contents("$dir/LICENSE.txt")));
    }
}
