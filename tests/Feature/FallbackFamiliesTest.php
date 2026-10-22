<?php

declare(strict_types=1);

namespace Papercut\Tests\Feature;

use Papercut\Text;
use Papercut\Tests\Support\{FakeBackend, LayoutHarness};
use Papercut\Text\FontRegistry;
use PHPUnit\Framework\TestCase;

final class FallbackFamiliesTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/fallback-cjk-' . getmypid() . '.ttf';
        copy(__DIR__ . '/../../resources/fonts/Inter/Inter-Italic.ttf', $this->file);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    /**
     * @param list<string> $fallbacks
     * @return array{LayoutHarness, list<array{font: string, text: string}>}
     */
    private function layoutWith(array $fallbacks): array
    {
        $backend = new FakeBackend(coverAll: [basename($this->file)]);
        $fonts = FontRegistry::withDefaults($backend)->register('Cjk', $this->file);
        $h = new LayoutHarness(backend: $backend, fonts: $fonts, fallbackFamilies: $fallbacks);
        $result = $h->layout(Text::make('Hello 日本語'), 400);

        return [$h, LayoutHarness::fontRuns($result->fragment)];
    }

    public function testMissingGlyphsWarnWithoutFallback(): void
    {
        [$h, $runs] = $this->layoutWith([]);
        $this->assertNotSame([], $h->warnings);
        $this->assertNotContains('fake:' . basename($this->file), array_column($runs, 'font'));
    }

    public function testFallbackFamilyFontIsUsedForUncoveredGlyphs(): void
    {
        [$h, $runs] = $this->layoutWith(['Cjk']);
        $this->assertSame([], $h->warnings);
        $cjk = array_values(array_filter($runs, fn (array $r): bool => $r["font"] === 'fake:' . basename($this->file)));
        $this->assertSame(['日本語'], array_column($cjk, 'text'));
    }
}
