<?php

declare(strict_types=1);

namespace Papercut\Tests\Html;

use Papercut\Exception\FontNotFound;
use Papercut\Html\{FontRequest, FontVariant, LocalFonts};
use Papercut\Text\{FontFace, FontSet, FontStyle};
use PHPUnit\Framework\TestCase;

final class LocalFontsTest extends TestCase
{
    private const DIR = __DIR__ . '/../../resources/fonts/Inter';

    private function request(string $family, bool $registered = true, ?FontSet $set = null): FontRequest
    {
        return new FontRequest($family, [new FontVariant(400, FontStyle::Normal)], $registered, $set ?? new FontSet());
    }

    public function test_unregistered_family_returns_null(): void
    {
        $this->assertNull(LocalFonts::embed()($this->request('Inter', false)));
        $this->assertNull(LocalFonts::link(static fn (FontFace $f): string => '/x.ttf')($this->request('Inter', false)));
    }

    public function test_embed_returns_registered_faces_as_data_uris(): void
    {
        $answer = LocalFonts::embed()($this->request('Inter'));

        $this->assertNotNull($answer);
        $faces = $answer->faceList();
        $this->assertCount(4, $faces);
        $expected = [
            [400, FontStyle::Normal, 'Inter-Regular.ttf'],
            [400, FontStyle::Italic, 'Inter-Italic.ttf'],
            [700, FontStyle::Normal, 'Inter-Bold.ttf'],
            [700, FontStyle::Italic, 'Inter-BoldItalic.ttf'],
        ];
        foreach ($expected as $i => [$weight, $style, $file]) {
            $this->assertSame($weight, $faces[$i]->weight);
            $this->assertSame($style, $faces[$i]->style);
            $this->assertSame('truetype', $faces[$i]->format);
            $this->assertSame('data:font/ttf;base64,' . base64_encode((string) file_get_contents(self::DIR . '/' . $file)), $faces[$i]->url);
        }
    }

    public function test_allowlist_is_case_insensitive(): void
    {
        $set = new FontSet([new FontFace('Acme Sans', self::DIR . '/Inter-Bold.ttf', 400)]);
        $source = LocalFonts::embed(['acme sans']);

        $answer = $source($this->request('Acme Sans', true, $set));
        $this->assertNotNull($answer);
        $this->assertCount(1, $answer->faceList());
        $this->assertNull($source($this->request('Inter', true, $set)));
    }

    public function test_link_keeps_non_null_faces(): void
    {
        $source = LocalFonts::link(static fn (FontFace $f): ?string => $f->weight === 700 ? '/f/' . basename($f->path) : null);

        $answer = $source($this->request('Inter'));

        $this->assertNotNull($answer);
        $faces = $answer->faceList();
        $this->assertCount(2, $faces);
        $this->assertSame([700, FontStyle::Normal, '/f/Inter-Bold.ttf', 'truetype'], [$faces[0]->weight, $faces[0]->style, $faces[0]->url, $faces[0]->format]);
        $this->assertSame([700, FontStyle::Italic, '/f/Inter-BoldItalic.ttf', 'truetype'], [$faces[1]->weight, $faces[1]->style, $faces[1]->url, $faces[1]->format]);
    }

    public function test_link_all_null_returns_null(): void
    {
        $this->assertNull(LocalFonts::link(static fn (FontFace $f): ?string => null)($this->request('Inter')));
    }

    public function test_embed_missing_file_throws_font_not_found(): void
    {
        $set = new FontSet([new FontFace('Gone', '/nope/gone.ttf')]);

        $this->expectException(FontNotFound::class);
        $this->expectExceptionMessage('Font file not found: /nope/gone.ttf');
        LocalFonts::embed()($this->request('Gone', true, $set));
    }
}
