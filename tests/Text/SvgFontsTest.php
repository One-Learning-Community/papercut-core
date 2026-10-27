<?php

declare(strict_types=1);

namespace Papercut\Tests\Text;

use Papercut\Tests\Support\FakeBackend;
use Papercut\Text\{FontRegistry, SvgFonts};
use PHPUnit\Framework\TestCase;

final class SvgFontsTest extends TestCase
{
    private const string INTER = __DIR__ . '/../../resources/fonts/Inter/';

    private string $cjk;
    /** @var list<string> */
    private array $warnings = [];

    protected function setUp(): void
    {
        $this->cjk = sys_get_temp_dir() . '/svg-fonts-cjk-' . getmypid() . '.ttf';
        copy(self::INTER . 'Inter-Italic.ttf', $this->cjk);
    }

    protected function tearDown(): void
    {
        @unlink($this->cjk);
    }

    /** @param list<string> $fallbacks */
    private function fonts(array $fallbacks = []): SvgFonts
    {
        $backend = new FakeBackend(coverAll: [basename($this->cjk)]);
        $registry = FontRegistry::withDefaults($backend)
            ->register('Brand Sans', self::INTER . 'Inter-Bold.ttf')
            ->register('Cjk', $this->cjk);

        return new SvgFonts($registry, 'Inter', $fallbacks, function (string $w): void {
            $this->warnings[] = $w;
        });
    }

    private static function file(string $id): string
    {
        return substr($id, strlen('fake:'));
    }

    public function test_without_a_font_family_the_element_family_is_used(): void
    {
        self::assertSame('Inter-Regular.ttf', self::file($this->fonts()->resolve(null, false, false, 'Hi')->id()));
    }

    public function test_bold_and_italic_pick_those_faces(): void
    {
        $fonts = $this->fonts();

        self::assertSame('Inter-Bold.ttf', self::file($fonts->resolve(null, true, false, 'Hi')->id()));
        self::assertSame('Inter-Italic.ttf', self::file($fonts->resolve(null, false, true, 'Hi')->id()));
        self::assertSame('Inter-BoldItalic.ttf', self::file($fonts->resolve(null, true, true, 'Hi')->id()));
    }

    public function test_the_first_registered_family_in_the_list_wins(): void
    {
        $font = $this->fonts()->resolve("Helvetica, 'brand sans', \"Inter\", sans-serif", false, false, 'Hi');

        self::assertSame('Inter-Bold.ttf', self::file($font->id()));
    }

    public function test_an_unknown_family_list_falls_back_to_the_element_family(): void
    {
        self::assertSame('Inter-Regular.ttf', self::file($this->fonts()->resolve('Helvetica, Arial, sans-serif', false, false, 'Hi')->id()));
    }

    public function test_text_the_font_cannot_show_uses_a_fallback_family(): void
    {
        $font = $this->fonts(['Missing', 'Cjk'])->resolve(null, false, false, '日本語');

        self::assertSame(basename($this->cjk), self::file($font->id()));
        self::assertSame([], $this->warnings);
    }

    public function test_missing_glyphs_are_warned_about_once_per_text(): void
    {
        $fonts = $this->fonts();
        $fonts->resolve(null, false, false, '日本語');
        $fonts->resolve(null, false, false, '日本語');

        self::assertSame(['Missing glyphs for "日本語" in SVG text'], $this->warnings);
    }
}
