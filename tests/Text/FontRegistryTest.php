<?php

declare(strict_types=1);

namespace Papercut\Tests\Text;

use Papercut\Backend\PhpPdf\PhpPdfBackend;
use Papercut\Exception\FontNotFound;
use Papercut\Text\{FontRegistry, FontStyle};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FontRegistryTest extends TestCase
{
    private const string DIR = __DIR__ . '/../../resources/fonts/Inter';

    private function registry(): FontRegistry
    {
        // Same family registered at 100/400/700/900 using bundled files as stand-ins.
        return (new FontRegistry(new PhpPdfBackend()))
            ->register('Test', self::DIR . '/Inter-Regular.ttf', 100)
            ->register('Test', self::DIR . '/Inter-Italic.ttf', 400)
            ->register('Test', self::DIR . '/Inter-Bold.ttf', 700)
            ->register('Test', self::DIR . '/Inter-BoldItalic.ttf', 900);
    }

    public function test_faces_lists_registered_faces(): void
    {
        $faces = FontRegistry::withDefaults(new PhpPdfBackend())->faces();
        $this->assertCount(4, $faces);
        $this->assertSame('Inter', $faces[0]['family']);
        $this->assertSame(400, $faces[0]['weight']);
        $this->assertSame(FontStyle::Normal, $faces[0]['style']);
        $this->assertFileExists($faces[0]['path']);
    }

    /** @return iterable<string, array{int, string}> */
    public static function weights(): iterable
    {
        // desired => expected file (by face registered at that weight)
        yield 'exact 400' => [400, 'Italic'];
        yield '450 falls below to 400' => [450, 'Italic'];
        yield '500 falls below to 400' => [500, 'Italic'];
        yield '300 goes down to 100' => [300, 'Regular'];
        yield '600 goes up to 700' => [600, 'Bold'];
        yield '800 goes up to 900' => [800, 'BoldItalic'];
        yield '950 has nothing above, goes down to 900' => [950, 'BoldItalic'];
        yield '50 has nothing below, goes up to 100' => [50, 'Regular'];
    }

    #[DataProvider('weights')]
    public function test_weight_matching(int $desired, string $face): void
    {
        $r = $this->registry();
        $expected = (new PhpPdfBackend())->loadFont(self::DIR . "/Inter-$face.ttf")->id();
        self::assertSame($expected, $r->resolve('Test', $desired)->id());
    }

    public function test_400_range_prefers_up_to_500_first(): void
    {
        $r = (new FontRegistry(new PhpPdfBackend()))
            ->register('W', self::DIR . '/Inter-Regular.ttf', 300)
            ->register('W', self::DIR . '/Inter-Bold.ttf', 500);
        $expected = (new PhpPdfBackend())->loadFont(self::DIR . '/Inter-Bold.ttf')->id();
        self::assertSame($expected, $r->resolve('W', 400)->id());
    }

    public function test_italic_falls_back_to_normal(): void
    {
        $r = (new FontRegistry(new PhpPdfBackend()))->register('N', self::DIR . '/Inter-Regular.ttf');
        self::assertSame($r->resolve('N'), $r->resolve('N', 400, FontStyle::Italic));
    }

    public function test_defaults_and_case_insensitive_family(): void
    {
        $r = FontRegistry::withDefaults(new PhpPdfBackend());
        self::assertTrue($r->has('inter'));
        self::assertSame(['Inter'], $r->families());
        self::assertNotSame($r->resolve('Inter'), $r->resolve('Inter', 700));
        self::assertNotSame($r->resolve('Inter'), $r->resolve('Inter', 400, FontStyle::Italic));
        self::assertSame($r->resolve('inter'), $r->resolve('INTER'));
    }

    public function test_unknown_family_lists_known(): void
    {
        $this->expectException(FontNotFound::class);
        $this->expectExceptionMessage('Unknown font family "Helvetica". Registered families: Inter');
        FontRegistry::withDefaults(new PhpPdfBackend())->resolve('Helvetica');
    }

    public function test_register_missing_file_fails_fast(): void
    {
        $this->expectException(FontNotFound::class);
        (new FontRegistry(new PhpPdfBackend()))->register('X', '/nope.ttf');
    }

    public function test_same_file_via_different_paths_shares_one_handle(): void
    {
        $r = (new FontRegistry(new PhpPdfBackend()))
            ->register('A', self::DIR . '/../Inter/Inter-Regular.ttf', 400)
            ->register('B', self::DIR . '/Inter-Regular.ttf', 400);
        self::assertSame($r->resolve('A'), $r->resolve('B'));
    }

    public function test_exact_500_wins_over_400(): void
    {
        $r = (new FontRegistry(new PhpPdfBackend()))
            ->register('M', self::DIR . '/Inter-Regular.ttf', 400)
            ->register('M', self::DIR . '/Inter-Bold.ttf', 500);
        $expected = (new PhpPdfBackend())->loadFont(self::DIR . '/Inter-Bold.ttf')->id();
        self::assertSame($expected, $r->resolve('M', 500)->id());
    }

    public function test_reregistering_replaces_the_path(): void
    {
        $r = (new FontRegistry(new PhpPdfBackend()))
            ->register('R', self::DIR . '/Inter-Regular.ttf')
            ->register('R', self::DIR . '/Inter-Bold.ttf');
        $expected = (new PhpPdfBackend())->loadFont(self::DIR . '/Inter-Bold.ttf')->id();
        self::assertSame($expected, $r->resolve('R')->id());
    }

    public function test_weight_bounds(): void
    {
        $r = new FontRegistry(new PhpPdfBackend());
        self::assertTrue($r->register('X', self::DIR . '/Inter-Regular.ttf', 1000)->has('X'));
        $this->expectException(\Papercut\Exception\InvalidValue::class);
        $r->register('Y', self::DIR . '/Inter-Regular.ttf', 1001);
    }

    public function test_register_rejects_bad_weight(): void
    {
        $this->expectException(\Papercut\Exception\InvalidValue::class);
        (new FontRegistry(new PhpPdfBackend()))->register('X', self::DIR . '/Inter-Regular.ttf', 0);
    }

    public function test_registry_matches_effective_faces(): void
    {
        $dir = self::DIR;
        $set = (new \Papercut\Text\FontSet())->with(
            new \Papercut\Text\FontFace('Brand', "$dir/Inter-Italic.ttf", 400, FontStyle::Italic),
            new \Papercut\Text\FontFace('inter', "$dir/Inter-Regular.ttf", 700),
        );
        $map = static fn (array $rows): array => array_map(static fn (array $r): string => strtolower($r['family']) . '|' . $r['weight'] . '|' . $r['style']->value . '|' . $r['path'], $rows);
        $expected = $map(array_map(static fn (\Papercut\Text\FontFace $f): array => [
            'family' => $f->family, 'weight' => $f->weight, 'style' => $f->style, 'path' => realpath($f->path) ?: $f->path,
        ], $set->effectiveFaces()));
        $actual = $map(FontRegistry::fromSet($set, new PhpPdfBackend())->faces());
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);

        $doc = \Papercut\Document::make(\Papercut\Page::a4()->body(
            \Papercut\Text::make('Plain bold italic')->weight(700),
            \Papercut\Text::make('Brand italic')->font('Brand')->italic(),
        ))->createdAt(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $bytes = $doc->toPdf(new \Papercut\Export\PdfOptions(fonts: $set))->bytes();
        $this->assertSame('b8a8f53d9ae21c5f8cb35a1b3bf644a583b48901', sha1($bytes));
    }
}
