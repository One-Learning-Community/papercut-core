<?php

declare(strict_types=1);

namespace Papercut\Tests\Text;

use Papercut\Backend\PhpPdf\PhpPdfBackend;
use Papercut\Math\Exception\UnsupportedFont;
use Papercut\Math\Math;
use Papercut\Text\{FontRegistry, FontSet};
use PHPUnit\Framework\TestCase;

final class MathFontTest extends TestCase
{
    /** @var list<string> */
    private array $temp = [];

    protected function tearDown(): void
    {
        foreach ($this->temp as $file) {
            @unlink($file);
        }
    }

    private function copyOfStix(): string
    {
        $path = sys_get_temp_dir() . '/papercut-math-' . bin2hex(random_bytes(4)) . '.ttf';
        copy(Math::stixTwoMathPath(), $path);
        $this->temp[] = $path;

        return $path;
    }

    public function test_default_math_font_is_stix(): void
    {
        $this->assertSame(Math::stixTwoMathPath(), (new FontSet())->mathFontPath());
        $this->assertSame('Papercut Math', FontSet::MATH_FAMILY);
    }

    public function test_custom_math_font_path_used(): void
    {
        $path = $this->copyOfStix();
        $set = (new FontSet())->withMathFont($path);
        $this->assertSame($path, $set->mathFontPath());

        $registry = FontRegistry::fromSet($set, new PhpPdfBackend());
        $math = $registry->mathFont();
        $paths = array_column(array_filter($registry->faces(), static fn (array $f): bool => $f['family'] === FontSet::MATH_FAMILY), 'path');
        $this->assertSame([realpath($path)], $paths);
        $this->assertInstanceOf(\Papercut\Math\Font\TrueTypeFont::class, $math['font']);
        $this->assertSame($math['font'], $registry->mathFont()['font']);
    }

    public function test_the_bundled_math_font_is_not_custom_however_its_path_is_spelled(): void
    {
        $bundled = Math::stixTwoMathPath();
        $roundabout = dirname($bundled) . '/../fonts/' . basename($bundled);

        $this->assertFalse((new FontSet())->withMathFont($bundled)->hasCustomMathFont());
        $this->assertFalse((new FontSet())->withMathFont($roundabout)->hasCustomMathFont());
        $this->assertSame($bundled, (new FontSet())->withMathFont($roundabout)->mathFontPath());
        $this->assertTrue((new FontSet())->withMathFont($this->copyOfStix())->hasCustomMathFont());
    }

    public function test_math_font_survives_other_with_calls(): void
    {
        $path = $this->copyOfStix();
        $set = (new FontSet())->withMathFont($path)->withFallbacks('Inter')->withWordName('Inter', 'Arial');
        $this->assertSame($path, $set->mathFontPath());
        $this->assertSame($path, $set->with()->mathFontPath());
    }

    public function test_non_math_font_rejected_early(): void
    {
        $inter = __DIR__ . '/../../resources/fonts/Inter/Inter-Regular.ttf';
        $this->expectException(UnsupportedFont::class);
        FontRegistry::fromSet((new FontSet())->withMathFont($inter), new PhpPdfBackend());
    }

    public function test_documents_without_equations_do_not_register_the_math_face(): void
    {
        $registry = FontRegistry::fromSet(new FontSet(), new PhpPdfBackend());
        $this->assertFalse($registry->has(FontSet::MATH_FAMILY));
        $this->assertCount(8, $registry->faces());

        $registry->mathFont();
        $this->assertTrue($registry->has(FontSet::MATH_FAMILY));
    }

    public function test_missing_custom_math_font_is_font_not_found(): void
    {
        $this->expectException(\Papercut\Exception\FontNotFound::class);
        FontRegistry::fromSet((new FontSet())->withMathFont('/no/such/math-font.ttf'), new PhpPdfBackend());
    }
}
