<?php

declare(strict_types=1);

namespace Papercut\Tests\Vector;

use Papercut\Exception\ImageUnreadable;
use Papercut\Vector\SvgDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SvgDocumentTest extends TestCase
{
    private const string NS = 'xmlns="http://www.w3.org/2000/svg"';

    private static function parse(string $attrs, string $body = '<rect width="1" height="1"/>'): SvgDocument
    {
        return SvgDocument::parse('<svg ' . self::NS . ' ' . $attrs . '>' . $body . '</svg>');
    }

    public function test_width_and_height_in_pixels_become_points(): void
    {
        $svg = self::parse('width="200" height="100"');

        self::assertEqualsWithDelta(150.0, $svg->widthPt, 1e-9);
        self::assertEqualsWithDelta(75.0, $svg->heightPt, 1e-9);
        self::assertSame([0.0, 0.0, 200.0, 100.0], $svg->viewBox);
    }

    /** @return iterable<string, array{string, float}> */
    public static function units(): iterable
    {
        yield 'px' => ['96px', 72.0];
        yield 'pt' => ['72pt', 72.0];
        yield 'pc' => ['6pc', 72.0];
        yield 'in' => ['1in', 72.0];
        yield 'cm' => ['2.54cm', 72.0];
        yield 'mm' => ['25.4mm', 72.0];
        yield 'exponent' => ['9.6e1', 72.0];
    }

    #[DataProvider('units')]
    public function test_length_units(string $width, float $points): void
    {
        $svg = self::parse('width="' . $width . '" height="10"');

        self::assertEqualsWithDelta($points, $svg->widthPt, 1e-9);
    }

    public function test_without_a_view_box_user_units_are_pixels_of_the_given_size(): void
    {
        // 1in is 96 user units wide, as a browser would draw it.
        $svg = self::parse('width="1in" height="0.5in"');

        self::assertSame([0.0, 0.0, 96.0, 48.0], $svg->viewBox);
    }

    public function test_a_view_box_alone_gives_the_size_in_pixels(): void
    {
        $svg = self::parse('viewBox="0 0 40 20"');

        self::assertEqualsWithDelta(30.0, $svg->widthPt, 1e-9);
        self::assertEqualsWithDelta(15.0, $svg->heightPt, 1e-9);
    }

    public function test_one_side_and_a_view_box_keep_the_aspect_ratio(): void
    {
        $svg = self::parse('width="100mm" viewBox="0 0 40 20"');

        self::assertEqualsWithDelta(\Papercut\mm(100)->points, $svg->widthPt, 1e-9);
        self::assertEqualsWithDelta(\Papercut\mm(50)->points, $svg->heightPt, 1e-9);
    }

    public function test_view_box_takes_commas_and_keeps_its_origin(): void
    {
        $svg = self::parse('width="10" height="10" viewBox="-5,10 20,30"');

        self::assertSame([-5.0, 10.0, 20.0, 30.0], $svg->viewBox);
    }

    public function test_percentages_and_no_size_fall_back_to_300_by_150_pixels(): void
    {
        $svg = self::parse('width="100%"');

        self::assertEqualsWithDelta(225.0, $svg->widthPt, 1e-9);
        self::assertEqualsWithDelta(112.5, $svg->heightPt, 1e-9);
    }

    public function test_an_invalid_view_box_is_ignored(): void
    {
        $svg = self::parse('width="20" height="10" viewBox="0 0 0 10"');

        self::assertSame([0.0, 0.0, 20.0, 10.0], $svg->viewBox);
    }

    /** @return iterable<string, array{string|null, string, bool}> */
    public static function aspectRatios(): iterable
    {
        yield 'default' => [null, 'xMidYMid', false];
        yield 'none' => ['none', 'none', false];
        yield 'slice' => ['xMinYMax slice', 'xMinYMax', true];
        yield 'defer is ignored' => ['defer xMaxYMin meet', 'xMaxYMin', false];
        yield 'nonsense' => ['wobbly', 'xMidYMid', false];
    }

    #[DataProvider('aspectRatios')]
    public function test_preserve_aspect_ratio(?string $value, string $align, bool $slice): void
    {
        $svg = self::parse('viewBox="0 0 10 10"' . ($value === null ? '' : ' preserveAspectRatio="' . $value . '"'));

        self::assertSame($align, $svg->align);
        self::assertSame($slice, $svg->slice);
    }

    public function test_the_title_is_the_alt_text(): void
    {
        self::assertSame('Company logo', self::parse('viewBox="0 0 1 1"', '<title> Company   logo </title><rect width="1" height="1"/>')->title);
        self::assertSame('', self::parse('viewBox="0 0 1 1"')->title);
    }

    public function test_it_knows_whether_there_is_text(): void
    {
        self::assertTrue(self::parse('viewBox="0 0 1 1"', '<g><text x="0" y="1">Hi</text></g>')->hasText);
        self::assertFalse(self::parse('viewBox="0 0 1 1"')->hasText);
    }

    public function test_it_lists_features_the_renderer_ignores(): void
    {
        $svg = self::parse('viewBox="0 0 10 10"', '<defs><clipPath id="c"><rect width="5" height="5"/></clipPath><filter id="f"/></defs>'
            . '<rect width="10" height="10" clip-path="url(#c)"/><rect width="1" height="1" filter="url(#f)"/><image href="x.png"/><mask id="m"/>');

        self::assertSame(['<clipPath>', '<filter>', '<image>', '<mask>'], $svg->unsupported);
    }

    public function test_the_xml_handed_on_has_a_view_box_and_no_doctype(): void
    {
        $svg = SvgDocument::parse('<?xml version="1.0"?><!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd"><svg ' . self::NS . ' width="20" height="10"><rect width="1" height="1"/></svg>');

        self::assertStringNotContainsString('DOCTYPE', $svg->xml);
        self::assertStringContainsString('viewBox="0 0 20 10"', $svg->xml);
        self::assertStringContainsString('width="15pt"', $svg->xml);
        self::assertStringContainsString('height="7.5pt"', $svg->xml);
    }

    public function test_scripts_and_event_handlers_are_removed(): void
    {
        $svg = self::parse('width="10" height="10" onload="go()"', '<script>go()</script><g ONCLICK="go()"><rect width="1" height="1" fill="red"/></g>');

        self::assertStringNotContainsString('go()', $svg->xml);
        self::assertStringContainsString('fill="red"', $svg->xml);
    }

    public function test_svg_without_the_namespace_is_accepted(): void
    {
        self::assertEqualsWithDelta(7.5, SvgDocument::parse('<svg width="10" height="10"/>')->widthPt, 1e-9);
    }

    /** @return iterable<string, array{string, string}> */
    public static function refused(): iterable
    {
        yield 'not xml' => ['this is not svg', 'not well-formed XML'];
        yield 'empty' => ['', 'empty'];
        yield 'another root' => ['<html><body/></html>', 'root element is <html>, not <svg>'];
        yield 'entity declaration' => ['<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x "y">]><svg width="1" height="1"/>', 'declares entities'];
        yield 'external entity' => ['<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg width="1" height="1"><text>&x;</text></svg>', 'declares entities'];
        yield 'use cycle' => ['<svg width="1" height="1"><g id="a"><use href="#a"/></g></svg>', '<use> refers to itself'];
    }

    #[DataProvider('refused')]
    public function test_refused_input(string $xml, string $message): void
    {
        $this->expectException(ImageUnreadable::class);
        $this->expectExceptionMessage($message);

        SvgDocument::parse($xml);
    }

    public function test_input_over_the_size_limit_is_refused(): void
    {
        $this->expectException(ImageUnreadable::class);
        $this->expectExceptionMessage('larger than');

        SvgDocument::parse('<svg width="1" height="1"><!--' . str_repeat('x', SvgDocument::MAX_BYTES) . '--></svg>');
    }

    public function test_use_that_multiplies_drawing_work_past_the_limit_is_refused(): void
    {
        // Each level draws the previous one ten times: 10^6 rectangles from a few hundred bytes.
        $defs = '<g id="l0"><rect width="1" height="1"/></g>';
        for ($i = 1; $i <= 6; $i++) {
            $defs .= sprintf('<g id="l%d">%s</g>', $i, str_repeat(sprintf('<use href="#l%d"/>', $i - 1), 10));
        }

        $this->expectException(ImageUnreadable::class);
        $this->expectExceptionMessage('too complex');

        SvgDocument::parse('<svg width="1" height="1"><defs>' . $defs . '</defs><use href="#l6"/></svg>');
    }

    public function test_long_path_data_counts_towards_the_limit(): void
    {
        $this->expectException(ImageUnreadable::class);
        $this->expectExceptionMessage('too complex');

        SvgDocument::parse('<svg width="1" height="1"><path d="M0 0' . str_repeat(' L1 1', SvgDocument::MAX_WORK) . '"/></svg>');
    }

    public function test_a_dense_chart_path_is_accepted(): void
    {
        // 300,000 points: well past what one element costs, well inside the budget.
        $svg = SvgDocument::parse('<svg viewBox="0 0 10 10"><path d="M0 0' . str_repeat(' L1.25 3.5', 300_000) . '"/></svg>');

        self::assertSame([0.0, 0.0, 10.0, 10.0], $svg->viewBox);
    }

    public function test_circles_count_for_more_than_rects(): void
    {
        // A circle or ellipse costs about three times what a rect does to draw.
        $circles = str_repeat('<circle r="1"/>', intdiv(SvgDocument::MAX_WORK, 15) + 1);

        $this->expectException(ImageUnreadable::class);
        $this->expectExceptionMessage('too complex');

        SvgDocument::parse('<svg width="1" height="1">' . $circles . '</svg>');
    }

    public function test_as_many_rects_are_fine(): void
    {
        $rects = str_repeat('<rect width="1" height="1"/>', intdiv(SvgDocument::MAX_WORK, 15) + 1);

        self::assertEqualsWithDelta(0.75, SvgDocument::parse('<svg width="1" height="1">' . $rects . '</svg>')->widthPt, 1e-9);
    }

    public function test_use_with_xlink_href_is_followed(): void
    {
        $this->expectException(ImageUnreadable::class);
        $this->expectExceptionMessage('<use> refers to itself');

        SvgDocument::parse('<svg xmlns:xlink="http://www.w3.org/1999/xlink" width="1" height="1"><g id="a"><use xlink:href="#a"/></g></svg>');
    }

    public function test_ordinary_use_is_fine(): void
    {
        $svg = SvgDocument::parse('<svg width="10" height="10"><defs><circle id="dot" r="1"/></defs><use href="#dot" x="2"/><use href="#dot" x="4"/><use href="#missing"/></svg>');

        self::assertEqualsWithDelta(7.5, $svg->widthPt, 1e-9);
    }
}
