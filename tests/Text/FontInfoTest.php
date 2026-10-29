<?php

declare(strict_types=1);

namespace Papercut\Tests\Text;

use Papercut\Text\{FontClass, FontInfo, FontSet, TrueTypeTables};
use PHPUnit\Framework\TestCase;

final class FontInfoTest extends TestCase
{
    private const string INTER = __DIR__ . '/../../resources/fonts/Inter/Inter-Regular.ttf';
    private const string COUSINE = __DIR__ . '/../../resources/fonts/Cousine/Cousine-Regular.ttf';

    public function test_inter_is_sans_variable(): void
    {
        $info = FontInfo::read(self::INTER);

        self::assertSame(FontClass::Sans, $info->class);
        self::assertFalse($info->fixedPitch);
        self::assertMatchesRegularExpression('/^[0-9A-F]{20}$/', (string) $info->panose);
        self::assertSame('02000503000000020004', $info->panose);
    }

    public function test_cousine_is_mono(): void
    {
        $info = (new FontSet())->info('cousine');

        self::assertNotNull($info);
        self::assertSame(FontClass::Mono, $info->class);
        self::assertTrue($info->fixedPitch);
    }

    public function test_unknown_family_has_no_info(): void
    {
        self::assertNull((new FontSet())->info('Nope'));
    }

    public function test_font_info_without_os2_is_auto(): void
    {
        $path = $this->withoutOs2();
        try {
            $info = FontInfo::read($path);
            self::assertSame(FontClass::Other, $info->class);
            self::assertNull($info->panose);
            self::assertFalse($info->fixedPitch);
        } finally {
            unlink($path);
        }
    }

    public function test_fixed_pitch_font_without_os2_is_mono_with_no_panose(): void
    {
        $path = $this->withoutOs2(self::COUSINE);
        try {
            $info = FontInfo::read($path);
            self::assertSame(FontClass::Mono, $info->class);
            self::assertNull($info->panose);
            self::assertTrue($info->fixedPitch);
        } finally {
            unlink($path);
        }
    }

    public function test_malformed_files_are_other_and_silent(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bad');
        // A full 12-byte header that claims 40 tables the file does not hold.
        file_put_contents($path, pack('Nnnnn', 0x10000, 40, 0, 0, 0));
        try {
            self::assertNull(TrueTypeTables::open($path));
            self::assertSame(FontClass::Other, FontInfo::read($path)->class);
            self::assertSame(FontClass::Other, FontInfo::read($path . '.missing')->class);
        } finally {
            unlink($path);
        }
    }

    public function test_table_running_past_the_file_is_null(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cut');
        file_put_contents($path, pack('Nnnnn', 0x10000, 1, 0, 0, 0) . pack('a4NNN', 'head', 0, 28, 500));
        try {
            self::assertNull(TrueTypeTables::open($path)?->table('head'));
        } finally {
            unlink($path);
        }
    }

    public function test_css_generics(): void
    {
        self::assertSame('system-ui, sans-serif', FontClass::Sans->cssGeneric());
        self::assertSame('serif', FontClass::Serif->cssGeneric());
        self::assertSame('monospace', FontClass::Mono->cssGeneric());
        self::assertSame('system-ui, sans-serif', FontClass::Script->cssGeneric());
        self::assertSame('system-ui, sans-serif', FontClass::Decorative->cssGeneric());
        self::assertSame('system-ui, sans-serif', FontClass::Other->cssGeneric());
    }

    /** @return iterable<string, array{string, FontClass}> */
    public static function panoseClasses(): iterable
    {
        yield 'text, cove' => ["\x02\x02", FontClass::Serif];
        yield 'text, normal sans' => ["\x02\x0B", FontClass::Sans];
        yield 'text, any' => ["\x02\x00", FontClass::Sans];
        yield 'text, flared' => ["\x02\x0E", FontClass::Sans];
        yield 'text, rounded' => ["\x02\x0F", FontClass::Sans];
        yield 'hand-written' => ["\x03\x00", FontClass::Script];
        yield 'decorative' => ["\x04\x00", FontClass::Decorative];
        yield 'symbol' => ["\x05\x00", FontClass::Other];
        yield 'no fit' => ["\x01\x00", FontClass::Other];
        yield 'any (all zeros)' => ["\x00\x00", FontClass::Other];
        yield 'kind PANOSE 1.0 does not define' => ["\x06\x00", FontClass::Other];
        yield 'highest byte' => ["\xFF\x00", FontClass::Other];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('panoseClasses')]
    public function test_panose_family_kind_decides_the_class(string $panose, FontClass $expected): void
    {
        $path = $this->withPanose($panose);
        try {
            self::assertSame($expected, FontInfo::read($path)->class);
        } finally {
            unlink($path);
        }
    }

    /** A font with its OS/2 table renamed away, so the directory no longer lists one. */
    private function withoutOs2(string $font = self::INTER): string
    {
        $data = (string) file_get_contents($font);
        $pos = strpos(substr($data, 12, 16 * self::tableCount($data)), 'OS/2');
        self::assertNotFalse($pos);
        $path = tempnam(sys_get_temp_dir(), 'no-os2');
        file_put_contents($path, substr_replace($data, 'XXXX', 12 + $pos, 4));

        return $path;
    }

    /** Inter with the first two PANOSE bytes replaced. */
    private function withPanose(string $head): string
    {
        $data = (string) file_get_contents(self::INTER);
        for ($i = 0, $count = self::tableCount($data); $i < $count; $i++) {
            $rec = unpack('a4tag/Nsum/Noffset/Nlength', $data, 12 + 16 * $i);
            if (is_array($rec) && $rec['tag'] === 'OS/2') {
                $path = tempnam(sys_get_temp_dir(), 'panose');
                file_put_contents($path, substr_replace($data, $head, $rec['offset'] + 32, 2));

                return $path;
            }
        }
        self::fail('no OS/2 table');
    }

    private static function tableCount(string $data): int
    {
        $count = unpack('n', $data, 4);
        self::assertIsArray($count);

        return (int) $count[1];
    }
}
