<?php

declare(strict_types=1);

namespace Papercut\Tests\Code;

use Papercut\Code\CodeTheme;
use PHPUnit\Framework\TestCase;

final class CodeThemeTest extends TestCase
{
    public function test_default_theme_editor_colours(): void
    {
        $c = CodeTheme::editorColors(CodeTheme::defaultPath());
        self::assertSame(['#f6f8fa', '#24292f'], [(string) $c['background'], (string) $c['foreground']]);
    }

    public function test_unreadable_theme_file_names_the_path(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('/nope.json');
        CodeTheme::editorColors('/nope.json');
    }

    public function test_theme_without_editor_colours_names_the_path(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'theme');
        self::assertIsString($path);
        file_put_contents($path, '{"colors":{}}');
        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage($path);
            CodeTheme::editorColors($path);
        } finally {
            unlink($path);
        }
    }

    public function test_default_theme_is_valid_json_named_papercut_light(): void
    {
        $json = json_decode((string) file_get_contents(CodeTheme::defaultPath()), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(CodeTheme::DEFAULT, $json['name']);
        self::assertSame('light', $json['type']);
    }
}
