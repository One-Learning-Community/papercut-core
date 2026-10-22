<?php

declare(strict_types=1);

namespace Papercut\Tests\Text;

use Papercut\Text\{FontFace, FontRegistry, FontSet, FontStyle};
use Papercut\Tests\Support\FakeBackend;
use PHPUnit\Framework\TestCase;

final class FontSetTest extends TestCase
{
    private const string INTER = __DIR__ . '/../../resources/fonts/Inter/Inter-Regular.ttf';

    public function test_registry_from_set_has_inter_and_custom_faces_and_fallbacks(): void
    {
        $set = (new FontSet())->with(new FontFace('Brand', self::INTER, 700, FontStyle::Italic))->withFallbacks('Inter', 'Brand');
        self::assertSame(['Inter', 'Brand'], $set->fallbacks);
        $registry = FontRegistry::fromSet($set, new FakeBackend());
        self::assertTrue($registry->has('Inter'));
        self::assertTrue($registry->has('Brand'));
        self::assertContains(['family' => 'Brand', 'weight' => 700, 'style' => FontStyle::Italic, 'path' => realpath(self::INTER)], $registry->faces());
    }

    public function test_set_is_immutable(): void
    {
        $a = new FontSet();
        $b = $a->with(new FontFace('X', self::INTER))->withFallbacks('X');
        self::assertSame([], $a->faces);
        self::assertSame([], $a->fallbacks);
        self::assertCount(1, $b->faces);
    }

    public function test_bundled_lists_the_four_inter_faces(): void
    {
        $faces = FontSet::bundled();
        self::assertCount(4, $faces);
        foreach ($faces as $face) {
            self::assertSame('Inter', $face->family);
            self::assertFileExists($face->path);
        }
        self::assertSame([400, 400, 700, 700], array_map(static fn (FontFace $f): int => $f->weight, $faces));
        self::assertSame([FontStyle::Normal, FontStyle::Italic, FontStyle::Normal, FontStyle::Italic], array_map(static fn (FontFace $f): FontStyle => $f->style, $faces));
    }

    public function test_effective_faces_dedupe_case_insensitively_last_wins(): void
    {
        $custom = __DIR__ . '/../../resources/fonts/Inter/Inter-Italic.ttf';
        $set = (new FontSet())->with(
            new FontFace('Brand', self::INTER),
            new FontFace('inter', $custom, 700),
        );
        $faces = $set->effectiveFaces();

        self::assertCount(5, $faces);
        self::assertSame(['Inter', 'Inter', 'Inter', 'Inter', 'Brand'], array_map(static fn (FontFace $f): string => $f->family === 'inter' ? 'Inter' : $f->family, $faces));
        self::assertSame(['inter', 700, FontStyle::Normal, $custom], [$faces[2]->family, $faces[2]->weight, $faces[2]->style, $faces[2]->path]);
        self::assertSame(FontSet::bundled()[0]->path, $faces[0]->path);
        self::assertSame(self::INTER, $faces[4]->path);
    }
}
