<?php

declare(strict_types=1);

namespace Papercut\Tests\Vector;

use Papercut\{Row, Svg};
use Papercut\Exception\ImageUnreadable;
use Papercut\Layout\{Fragment, Status};
use Papercut\Paint\{Clip, DrawSvg};
use Papercut\Tests\Support\LayoutHarness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use function Papercut\pt;

final class SvgLayoutTest extends TestCase
{
    private static function svg(string $attrs, string $body = '<rect width="10" height="10"/>'): Svg
    {
        return Svg::fromString('<svg xmlns="http://www.w3.org/2000/svg" ' . $attrs . '>' . $body . '</svg>');
    }

    /** @return array{Clip, DrawSvg} */
    private static function drawn(Fragment $f): array
    {
        $clips = array_values(array_filter($f->ops(), static fn (object $op): bool => $op instanceof Clip));
        self::assertCount(1, $clips);
        self::assertCount(1, $clips[0]->ops);
        $draw = $clips[0]->ops[0];
        self::assertInstanceOf(DrawSvg::class, $draw);
        return [$clips[0], $draw];
    }

    /** @param array{float, float, float, float} $expected */
    private static function assertRect(array $expected, float $x, float $y, float $w, float $h): void
    {
        self::assertEqualsWithDelta($expected, [$x, $y, $w, $h], 1e-6);
    }

    public function test_from_string_refuses_bad_svg_straight_away(): void
    {
        $this->expectException(ImageUnreadable::class);
        $this->expectExceptionMessage('not well-formed XML');

        Svg::fromString('<svg');
    }

    public function test_a_missing_file_is_reported_when_it_is_read(): void
    {
        $svg = Svg::file('/nope/logo.svg');

        $this->expectException(ImageUnreadable::class);
        $this->expectExceptionMessage('Cannot read SVG "/nope/logo.svg": file not found or not readable');

        $svg->document();
    }

    public function test_a_file_is_read_and_parsed_once(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'svg');
        self::assertIsString($path);
        file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="20"/>');
        try {
            $svg = Svg::file($path)->width(pt(100));
            self::assertSame(['kind' => 'file', 'value' => $path], $svg->source());
            $first = $svg->document();
            self::assertSame($first, $svg->document());
            self::assertEqualsWithDelta(30.0, $first->widthPt, 1e-9);
        } finally {
            unlink($path);
        }
    }

    public function test_the_intrinsic_size_comes_from_the_svg(): void
    {
        $f = (new LayoutHarness())->layout(self::svg('width="200" height="100"'), 500)->fragment;
        self::assertNotNull($f);

        self::assertEqualsWithDelta(150.0, $f->width, 1e-9);
        self::assertEqualsWithDelta(75.0, $f->height, 1e-9);
        [$clip, $draw] = self::drawn($f);
        self::assertRect([0, 0, 150, 75], $clip->x, $clip->y, $clip->width, $clip->height);
        self::assertRect([0, 0, 150, 75], $draw->x, $draw->y, $draw->width, $draw->height);
    }

    public function test_width_alone_keeps_the_aspect_ratio(): void
    {
        $f = (new LayoutHarness())->layout(self::svg('viewBox="0 0 40 20"')->width(pt(300)), 500)->fragment;
        self::assertNotNull($f);

        self::assertEqualsWithDelta(150.0, $f->height, 1e-9);
    }

    public function test_it_shrinks_to_the_width_available(): void
    {
        $f = (new LayoutHarness())->layout(self::svg('width="800" height="400"'), 300)->fragment;
        self::assertNotNull($f);

        self::assertEqualsWithDelta(300.0, $f->width, 1e-9);
        self::assertEqualsWithDelta(150.0, $f->height, 1e-9);
    }

    public function test_the_view_box_origin_shifts_the_drawing(): void
    {
        // User unit (10, 20) lands on the box's top-left corner; one unit is 2pt.
        $f = (new LayoutHarness())->layout(self::svg('viewBox="10 20 50 25"')->width(pt(100)), 500)->fragment;
        self::assertNotNull($f);

        [$clip, $draw] = self::drawn($f);
        self::assertRect([0, 0, 100, 50], $clip->x, $clip->y, $clip->width, $clip->height);
        self::assertRect([-20, -40, 100, 50], $draw->x, $draw->y, $draw->width, $draw->height);
    }

    public function test_padding_and_border_move_the_drawing_inside(): void
    {
        $f = (new LayoutHarness())->layout(self::svg('width="80" height="40"')->padding(pt(5))->border(pt(1)), 500)->fragment;
        self::assertNotNull($f);

        self::assertEqualsWithDelta(72.0, $f->width, 1e-9);
        [$clip, $draw] = self::drawn($f);
        self::assertRect([6, 6, 60, 30], $clip->x, $clip->y, $clip->width, $clip->height);
        self::assertRect([6, 6, 60, 30], $draw->x, $draw->y, $draw->width, $draw->height);
    }

    /** @return iterable<string, array{string, array{float, float, float, float}}> */
    public static function aspectRatios(): iterable
    {
        // A 20 x 10 view box in a 100 x 100 box.
        yield 'meet centres it' => ['xMidYMid meet', [0, 25, 100, 50]];
        yield 'meet at the end' => ['xMaxYMax meet', [0, 50, 100, 50]];
        yield 'meet at the start' => ['xMinYMin', [0, 0, 100, 50]];
        yield 'slice fills and crops' => ['xMidYMid slice', [-50, 0, 200, 100]];
        yield 'slice from the left' => ['xMinYMid slice', [0, 0, 200, 100]];
        yield 'none stretches' => ['none', [0, 0, 100, 100]];
    }

    /** @param array{float, float, float, float} $expected */
    #[DataProvider('aspectRatios')]
    public function test_preserve_aspect_ratio_places_the_view_box(string $value, array $expected): void
    {
        $svg = self::svg('viewBox="0 0 20 10" preserveAspectRatio="' . $value . '"')->width(pt(100))->height(pt(100));
        $f = (new LayoutHarness())->layout($svg, 500)->fragment;
        self::assertNotNull($f);

        [$clip, $draw] = self::drawn($f);
        self::assertRect([0, 0, 100, 100], $clip->x, $clip->y, $clip->width, $clip->height);
        self::assertRect($expected, $draw->x, $draw->y, $draw->width, $draw->height);
    }

    public function test_it_does_not_split_and_moves_to_a_fresh_page(): void
    {
        $result = (new LayoutHarness())->layout(self::svg('width="100" height="100"'), 500, 50, fresh: false);

        self::assertSame(Status::DoesNotFit, $result->status);
    }

    public function test_an_empty_page_too_short_shrinks_it(): void
    {
        $f = (new LayoutHarness())->layout(self::svg('width="100" height="100"'), 500, 50)->fragment;
        self::assertNotNull($f);

        self::assertEqualsWithDelta(50.0, $f->width, 1e-9);
        self::assertEqualsWithDelta(50.0, $f->height, 1e-9);
    }

    public function test_it_sits_in_a_row_at_its_own_width(): void
    {
        $f = (new LayoutHarness())->layout(Row::make(self::svg('width="80" height="40"'), self::svg('width="40" height="40"')), 500)->fragment;
        self::assertNotNull($f);

        $children = $f->children();
        self::assertCount(2, $children);
        self::assertEqualsWithDelta(60.0, $children[1]['x'], 1e-6);
    }

    public function test_features_the_renderer_skips_are_warned_about_once(): void
    {
        $h = new LayoutHarness();
        $svg = self::svg('width="10" height="10"', '<clipPath id="c"><rect width="1" height="1"/></clipPath><mask id="m"/><rect width="10" height="10" clip-path="url(#c)"/>');
        $h->layout($svg, 500);
        $h->layout($svg, 500);

        self::assertCount(1, $h->warnings);
        self::assertStringContainsString('SVG uses <clipPath>, <mask>, which PDF output does not draw yet', $h->warnings[0]);
    }

    public function test_the_drawing_carries_the_fonts_for_its_text(): void
    {
        $f = (new LayoutHarness())->layout(self::svg('width="10" height="10"', '<text y="8">Hi</text>'), 500)->fragment;
        self::assertNotNull($f);

        [, $draw] = self::drawn($f);
        self::assertTrue($draw->svg->hasText);
        self::assertNotSame('', $draw->fonts->resolve(null, false, false, 'Hi')->id());
    }
}
