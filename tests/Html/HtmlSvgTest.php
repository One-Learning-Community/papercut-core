<?php

declare(strict_types=1);

namespace Papercut\Tests\Html;

use Papercut\{Document, Node, Page, Row, Svg};
use Papercut\Export\HtmlOptions;
use Papercut\Vector\SvgDocument;
use PHPUnit\Framework\TestCase;
use function Papercut\pt;

final class HtmlSvgTest extends TestCase
{
    private const string LOGO = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 20"><title>Acme logo</title><rect width="40" height="20" fill="#1d4ed8"/></svg>';

    /** @param list<string> $warnings only those about SVG */
    private static function html(Node $node, array &$warnings = []): string
    {
        $options = new HtmlOptions(onWarning: static function (string $w) use (&$warnings): void {
            if (str_contains($w, 'SVG')) {
                $warnings[] = $w;
            }
        });

        return Document::make(Page::a4()->body($node))->toHtml($options)->bytes();
    }

    /** @return list<\DOMElement> */
    private static function imgs(string $html): array
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $out = [];
        foreach ((new \DOMXPath($dom))->query('//img') ?: [] as $node) {
            self::assertInstanceOf(\DOMElement::class, $node);
            $out[] = $node;
        }
        return $out;
    }

    public function test_svg_is_an_img_with_a_data_uri_never_inline_markup(): void
    {
        $html = self::html(Svg::fromString(self::LOGO));

        $imgs = self::imgs($html);
        self::assertCount(1, $imgs);
        $src = $imgs[0]->getAttribute('src');
        self::assertStringStartsWith('data:image/svg+xml;base64,', $src);
        self::assertSame(SvgDocument::parse(self::LOGO)->xml, base64_decode(substr($src, strlen('data:image/svg+xml;base64,')), true));
        self::assertStringNotContainsString('<svg', $html);
    }

    public function test_the_title_is_the_alt_text(): void
    {
        self::assertSame('Acme logo', self::imgs(self::html(Svg::fromString(self::LOGO)))[0]->getAttribute('alt'));
    }

    public function test_box_styles_are_kept(): void
    {
        $img = self::imgs(self::html(Svg::fromString(self::LOGO)->width(pt(120))->padding(pt(4))))[0];

        self::assertStringContainsString('width:120pt', $img->getAttribute('style'));
        self::assertStringContainsString('padding:4pt', $img->getAttribute('style'));
    }

    public function test_in_a_row_it_gets_flex_item_declarations(): void
    {
        $img = self::imgs(self::html(Row::make(Svg::fromString(self::LOGO)->grow(1))))[0];

        self::assertStringContainsString('flex:', $img->getAttribute('style'));
    }

    public function test_scripts_and_event_handlers_are_removed(): void
    {
        $svg = Svg::fromString('<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" onload="alert(1)"><script>alert(2)</script><rect width="10" height="10" onclick="alert(3)"/></svg>');
        $src = self::imgs(self::html($svg))[0]->getAttribute('src');
        $xml = (string) base64_decode(substr($src, strlen('data:image/svg+xml;base64,')), true);

        self::assertStringContainsString('<rect', $xml);
        self::assertStringNotContainsString('alert', $xml);
        self::assertStringNotContainsString('script', $xml);
    }

    public function test_text_in_the_browser_is_reported_as_approximated(): void
    {
        $warnings = [];
        self::html(Svg::fromString('<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><text y="8">Hi</text></svg>'), $warnings);

        self::assertCount(1, $warnings);
        self::assertStringContainsString('SVG text', $warnings[0]);
    }

    public function test_no_report_without_text(): void
    {
        $warnings = [];
        self::html(Svg::fromString(self::LOGO), $warnings);

        self::assertSame([], $warnings);
    }
}
