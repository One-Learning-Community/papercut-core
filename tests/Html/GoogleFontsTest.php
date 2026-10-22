<?php

declare(strict_types=1);

namespace Papercut\Tests\Html;

use Papercut\Html\{FontAnswer, FontFaceSrc, FontRequest, FontVariant, GoogleFonts};
use Papercut\Text\{FontSet, FontStyle};
use PHPUnit\Framework\TestCase;

final class GoogleFontsTest extends TestCase
{
    /** @param list<array{int, int}> $faces [ital, weight] pairs */
    private function request(string $family, array $faces): FontRequest
    {
        $variants = array_map(
            static fn (array $f): FontVariant => new FontVariant($f[1], $f[0] === 1 ? FontStyle::Italic : FontStyle::Normal),
            $faces,
        );

        return new FontRequest($family, $variants, true, new FontSet());
    }

    private function url(GoogleFonts $source, FontRequest $request): ?string
    {
        return $source($request)?->url();
    }

    public function test_regular_only_has_no_axes(): void
    {
        $this->assertSame(
            'https://fonts.googleapis.com/css2?family=Inter&display=swap',
            $this->url(new GoogleFonts(), $this->request('Inter', [[0, 400]])),
        );
    }

    public function test_weights_only(): void
    {
        $this->assertSame(
            'https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap',
            $this->url(new GoogleFonts(), $this->request('Inter', [[0, 700], [0, 400]])),
        );
    }

    public function test_italics_are_sorted_tuples(): void
    {
        $this->assertSame(
            'https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,400;0,700;1,400;1,700&display=swap',
            $this->url(new GoogleFonts(), $this->request('Inter', [[1, 400], [0, 700], [0, 400], [1, 700]])),
        );
    }

    public function test_italic_only(): void
    {
        $this->assertSame(
            'https://fonts.googleapis.com/css2?family=Inter:ital,wght@1,400&display=swap',
            $this->url(new GoogleFonts(), $this->request('Inter', [[1, 400]])),
        );
    }

    public function test_family_name_encoding(): void
    {
        $this->assertSame(
            'https://fonts.googleapis.com/css2?family=Noto+Sans+JP&display=swap',
            $this->url(new GoogleFonts(), $this->request('Noto Sans JP', [[0, 400]])),
        );
        $this->assertSame(
            'https://fonts.googleapis.com/css2?family=Cr%C3%A8me+%26+Co&display=swap',
            $this->url(new GoogleFonts(), $this->request('Crème & Co', [[0, 400]])),
        );
    }

    public function test_host_and_display(): void
    {
        $this->assertSame(
            'https://fonts.bunny.net/css2?family=Inter&display=optional',
            $this->url(new GoogleFonts('https://fonts.bunny.net/', 'optional'), $this->request('Inter', [[0, 400]])),
        );
    }

    public function test_allowlist_is_case_insensitive(): void
    {
        $source = new GoogleFonts(families: ['inter']);

        $this->assertNotNull($source($this->request('Inter', [[0, 400]])));
        $this->assertNull($source($this->request('Roboto', [[0, 400]])));
    }

    public function test_invalid_host_and_display_throw(): void
    {
        foreach (['ftp://x', 'https://x/?a=1', 'https://x/#f', 'fonts.googleapis.com', 'https://fonts.bunny.net ', "https://x\n", 'https://x/a b', 'https://x/"', "https://x/'", 'https://x/<', 'https://x/>', 'https://x/\\a', ' https://x', ''] as $host) {
            try {
                new GoogleFonts($host);
                $this->fail("Host accepted: $host");
            } catch (\InvalidArgumentException $e) {
                $this->assertSame("GoogleFonts host must be an http or https URL with no query or fragment: '$host'", $e->getMessage());
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("GoogleFonts display must be one of auto, block, swap, fallback, optional: 'fast'");
        new GoogleFonts(display: 'fast');
    }

    public function test_font_request_sorts_dedupes_and_rejects_empty(): void
    {
        $request = new FontRequest('Inter', [
            new FontVariant(400, FontStyle::Italic),
            new FontVariant(700, FontStyle::Normal),
            new FontVariant(400, FontStyle::Normal),
            new FontVariant(700, FontStyle::Normal),
        ], false, new FontSet());

        $this->assertSame(
            [[400, 'normal'], [700, 'normal'], [400, 'italic']],
            array_map(static fn (FontVariant $v): array => [$v->weight, $v->style->value], $request->faces),
        );

        $this->expectException(\InvalidArgumentException::class);
        new FontRequest('Inter', [], false, new FontSet());
    }

    public function test_font_answer_kinds(): void
    {
        $sheet = FontAnswer::stylesheet('https://x/css');
        $this->assertSame('https://x/css', $sheet->url());
        $this->assertSame([], $sheet->faceList());

        $face = new FontFaceSrc(400, FontStyle::Normal, 'https://x/a.woff2', 'woff2');
        $faces = FontAnswer::faces($face);
        $this->assertNull($faces->url());
        $this->assertSame([$face], $faces->faceList());
    }
}
