<?php

declare(strict_types=1);

namespace Quoin\Tests\Html;

use Quoin\{Column, Document, Node, Page, Row, Svg, Text};
use Quoin\Export\{FontMode, HtmlOptions};
use Quoin\Vector\SvgDocument;
use PHPUnit\Framework\TestCase;
use function Quoin\pt;

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

    public function test_svg_alt_overrides_title(): void
    {
        $html = self::html(Svg::fromString(self::LOGO)->alt('Number line'));
        self::assertSame('Number line', self::imgs($html)[0]->getAttribute('alt'));
    }

    public function test_an_svg_without_a_title_has_empty_alt(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 20"><rect width="40" height="20"/></svg>';
        $html = self::html(Svg::fromString($svg));
        self::assertSame('', self::imgs($html)[0]->getAttribute('alt'));
        self::assertStringContainsString(' alt=""', $html);
    }

    public function test_empty_alt_marks_svg_decorative(): void
    {
        $html = self::html(Svg::fromString(self::LOGO)->alt(''));
        self::assertSame('', self::imgs($html)[0]->getAttribute('alt'));
        self::assertStringContainsString(' alt=""', $html);
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

    private const string GRADIENT = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 20"><defs><linearGradient id="g"><stop offset="0" stop-color="red"/></linearGradient></defs><rect width="40" height="20" fill="url(#g)"/><text x="1" y="10">Hi</text></svg>';

    /** @param list<string> $warnings only those about SVG */
    private static function inlineHtml(Node $node, array &$warnings = [], bool $strict = false): string
    {
        $options = new HtmlOptions(onWarning: static function (string $w) use (&$warnings): void {
            if (str_contains($w, 'SVG')) {
                $warnings[] = $w;
            }
        }, strict: $strict, inlineSvgs: true);

        return Document::make(Page::a4()->body($node))->toHtml($options)->bytes();
    }

    public function test_svgs_are_images_by_default(): void
    {
        self::assertStringContainsString('<img src="data:image/svg+xml;base64,', self::html(Svg::fromString(self::GRADIENT)));
    }

    public function test_inline_svgs_writes_svg_markup(): void
    {
        $html = self::inlineHtml(Svg::fromString(self::LOGO));

        self::assertStringContainsString('<svg', $html);
        self::assertStringNotContainsString('data:image/svg+xml', $html);
        self::assertTrue((new HtmlOptions())->withInlineSvgs(true)->inlineSvgs());
        self::assertTrue((new HtmlOptions(inlineSvgs: true))->withFullPage(true)->inlineSvgs());
        self::assertTrue(HtmlOptions::from(new HtmlOptions(inlineSvgs: true))->inlineSvgs());
    }

    public function test_same_svg_twice_gets_two_prefixes(): void
    {
        $html = self::inlineHtml(Row::make(Svg::fromString(self::GRADIENT), Svg::fromString(self::GRADIENT)));

        $hash = substr(sha1(Svg::fromString(self::GRADIENT)->document()->xml), 0, 6);
        self::assertSame(1, substr_count($html, 'id="pc-' . $hash . '-1-g"'));
        self::assertSame(1, substr_count($html, 'id="pc-' . $hash . '-2-g"'));
        self::assertSame(1, substr_count($html, 'url(#pc-' . $hash . '-1-g)'));
        self::assertSame(1, substr_count($html, 'url(#pc-' . $hash . '-2-g)'));
    }

    public function test_different_svgs_get_different_prefixes_in_every_export(): void
    {
        $a = self::inlineHtml(Svg::fromString(self::GRADIENT));
        $b = self::inlineHtml(Svg::fromString(self::LOGO . ' '));
        $c = self::inlineHtml(Svg::fromString(str_replace('#1d4ed8', '#000', self::LOGO)));
        self::assertSame(1, preg_match('/pc-([0-9a-f]{6})-1-/', $a, $ma));
        self::assertSame($a, self::inlineHtml(Svg::fromString(self::GRADIENT)));
        self::assertStringNotContainsString('pc-' . ($ma[1] ?? '?'), $c);
        self::assertNotSame($b, $c);
    }

    public function test_inline_svg_text_note_only_when_not_inline(): void
    {
        $off = [];
        self::html(Svg::fromString(self::GRADIENT), $off);
        self::assertCount(1, $off);
        self::assertStringContainsString("SVG text in the browser's own fonts (set inlineSvgs to use the page's fonts)", $off[0]);

        $on = [];
        self::inlineHtml(Svg::fromString(self::GRADIENT), $on, strict: true);
        self::assertSame([], $on);
    }

    /** @return array<string, array{string}> */
    public static function foreignObjects(): array
    {
        return [
            'foreignObject' => ['<foreignObject width="10" height="10"><div xmlns="http://www.w3.org/1999/xhtml">x</div></foreignObject>', 'foreignObject'],
            'lowercase foreignobject' => ['<foreignobject width="10" height="10"><textarea/></foreignobject>', 'foreignobject'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('foreignObjects')]
    public function test_an_svg_with_foreign_object_stays_an_image(string $inner, string $name): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10">' . $inner . '<text x="1" y="5">Hi</text></svg>';
        $notes = [];
        $html = self::inlineHtml(Svg::fromString($svg)->alt('Box'), $notes);

        self::assertStringContainsString('<img src="data:image/svg+xml;base64,', $html);
        self::assertStringContainsString('alt="Box"', $html);
        self::assertCount(2, $notes);
        self::assertStringContainsString("SVG kept as an image (<foreignObject> can't be written inline)", $notes[0]);
        self::assertStringContainsString("SVG text in the browser's own fonts", $notes[1]);
        self::assertStringNotContainsString('inlineSvgs', $notes[1]);
        self::assertSame(1, \Dom\HTMLDocument::createFromString('<!doctype html><body>' . $html, LIBXML_NOERROR)->getElementsByTagName('img')->length);
    }

    public function test_an_svg_from_an_editor_is_inlined_without_the_editor_data(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"'
            . ' xmlns:inkscape="http://www.inkscape.org/namespaces/inkscape" xmlns:sodipodi="http://sodipodi.sourceforge.net/DTD/sodipodi-0.dtd"'
            . ' xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" viewBox="0 0 10 10" sodipodi:docname="logo.svg" inkscape:version="1.3">'
            . '<sodipodi:namedview id="namedview1" inkscape:zoom="2" inkscape:current-layer="layer1"/>'
            . '<metadata id="metadata1"><rdf:RDF><rdf:Description/></rdf:RDF></metadata>'
            . '<defs id="defs1"><inkscape:perspective id="p1"/><linearGradient id="g"><stop offset="0" stop-color="red"/></linearGradient></defs>'
            . '<g inkscape:label="Layer 1" inkscape:groupmode="layer" id="layer1"><rect id="r" width="5" height="5" fill="url(#g)" xlink:title="x"/>'
            . '<text x="1" y="5">Hi</text></g></svg>';
        $notes = [];
        $html = self::inlineHtml(Svg::fromString($svg), $notes);

        self::assertStringNotContainsString('data:image/svg+xml', $html);
        self::assertSame([], $notes);
        foreach (['sodipodi', 'inkscape', 'rdf', 'namedview', 'metadata', 'perspective'] as $gone) {
            self::assertStringNotContainsString($gone, $html);
        }
        $doc = \Dom\HTMLDocument::createFromString('<!doctype html><body>' . $html, LIBXML_NOERROR);
        $root = $doc->getElementsByTagName('svg')->item(0);
        self::assertNotNull($root);
        self::assertSame(1, $root->getElementsByTagName('rect')->length);
        self::assertSame(1, $root->getElementsByTagName('text')->length);
        self::assertSame(1, $root->getElementsByTagName('linearGradient')->length);
        self::assertSame(1, $root->getElementsByTagName('g')->length);
        self::assertMatchesRegularExpression('/url\(#pc-[0-9a-f]{6}-1-g\)/', $html);
        self::assertStringContainsString('xlink:title="x"', $html);
    }

    public function test_a_normal_svg_is_inlined_and_metadata_is_dropped(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 10 10"><metadata><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"/></metadata>'
            . '<defs><linearGradient id="g"><stop offset="0" stop-color="red"/></linearGradient></defs><rect id="r" width="5" height="5" fill="url(#g)"><animate attributeName="x" from="0" to="1" dur="1s"/></rect>'
            . '<use xlink:href="#r"/><text x="1" y="5">Hi</text></svg>';
        $notes = [];
        $html = self::inlineHtml(Svg::fromString($svg), $notes);

        self::assertStringContainsString('<svg', $html);
        self::assertStringNotContainsString('metadata', $html);
        self::assertStringNotContainsString('data:image/svg+xml', $html);
        self::assertSame([], $notes);
    }

    public function test_comments_are_dropped_from_inline_svg(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><!-- note --><title>T<!-- inner --></title><rect width="1" height="1"/></svg>';
        $html = self::inlineHtml(Svg::fromString($svg)->alt('Box'));

        self::assertStringContainsString('<svg', $html);
        self::assertStringNotContainsString('<!--', $html);
        self::assertStringContainsString('<rect', $html);
    }

    /** @return array<string, array{string, string}> */
    public static function cdataInline(): array
    {
        $bomb = 'x&gt;&lt;/svg&gt;&lt;img src=x onerror=alert(1)&gt;&lt;!--';

        return [
            'title' => ['<title><![CDATA[x></svg><img src=x onerror=alert(1)><!--]]></title>', 'title'],
            'desc' => ['<desc><![CDATA[x></svg><img src=x onerror=alert(1)><!--]]></desc>', 'desc'],
            'text' => ['<text x="1" y="5"><![CDATA[x></svg><img src=x onerror=alert(1)><!--]]></text>', 'text'],
            'escaped text with comment and cdata end' => ['<text x="1" y="5">' . $bomb . ']]&gt;</text>', 'text'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cdataInline')]
    public function test_cdata_and_markup_like_text_stay_text_inline(string $inner, string $holder): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10">' . $inner . '<rect width="1" height="1"/></svg>';
        $html = self::inlineHtml(Svg::fromString($svg)->alt('Box'));

        self::assertStringContainsString('<svg', $html);
        self::assertStringNotContainsString('<![CDATA[', $html);
        $doc = \Dom\HTMLDocument::createFromString('<!doctype html><body>' . $html . '<p id="after">x</p>', LIBXML_NOERROR);
        self::assertSame('BODY', $doc->getElementById('after')?->parentNode?->nodeName);
        self::assertSame(0, $doc->getElementsByTagName('img')->length);
        $svgEl = $doc->getElementsByTagName('svg')->item(0);
        self::assertNotNull($svgEl);
        self::assertSame(1, $svgEl->getElementsByTagName('rect')->length);
        self::assertStringContainsString('<img src=x onerror=alert(1)>', (string) $svgEl->getElementsByTagName($holder)->item(0)?->textContent);
    }

    public function test_an_undeclared_entity_from_an_external_dtd_keeps_the_svg_safe(): void
    {
        $svg = '<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">'
            . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><title>a&nbsp;b</title><rect width="1" height="1"/></svg>';
        $html = self::inlineHtml(Svg::fromString($svg)->alt('Box'));

        self::assertStringNotContainsString('<!DOCTYPE', $html);
        self::assertStringNotContainsString('&nbsp;', $html);
        $doc = \Dom\HTMLDocument::createFromString('<!doctype html><body>' . $html . '<p id="after">x</p>', LIBXML_NOERROR);
        self::assertSame('BODY', $doc->getElementById('after')?->parentNode?->nodeName);
    }

    private static function fontSvg(string $family): Svg
    {
        return Svg::fromString('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 20"><text x="1" y="10" font-family="' . $family . '">Label</text></svg>');
    }

    private static function fontHtml(Svg $svg, FontMode $mode = FontMode::Embed): string
    {
        $body = Column::make(Text::make('Body')->font('Cousine'), $svg->font('Cousine'));

        return Document::make(Page::a4()->body($body))->toHtml(new HtmlOptions(fontMode: $mode, inlineSvgs: true))->bytes();
    }

    private static function svgFamily(string $html): string
    {
        $dom = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $text = $dom->querySelector('svg text');
        self::assertNotNull($text);

        return $text->getAttribute('font-family') ?? '';
    }

    public function test_registered_svg_family_gets_a_font_face(): void
    {
        $html = self::fontHtml(self::fontSvg('Inter'));

        self::assertSame('"Inter", system-ui, sans-serif', self::svgFamily($html));
        self::assertStringContainsString('@font-face{font-family:"Inter";', $html);
        self::assertStringContainsString('@font-face{font-family:"Cousine";', $html);
    }

    public function test_unregistered_svg_family_becomes_the_pdf_family(): void
    {
        $html = self::fontHtml(self::fontSvg('Arial'));

        self::assertSame('"Cousine", monospace', self::svgFamily($html));
        self::assertStringNotContainsString('Arial', $html);
    }

    public function test_first_registered_family_in_the_list_wins(): void
    {
        self::assertSame('"Inter", system-ui, sans-serif', self::svgFamily(self::fontHtml(self::fontSvg('Arial, inter'))));
    }

    public function test_svg_family_is_added_to_the_font_set_when_no_other_text_uses_it(): void
    {
        $svg = self::fontSvg('Inter');
        $html = Document::make(Page::a4()->body($svg->font('Cousine')))->toHtml(new HtmlOptions(fontMode: FontMode::Embed, inlineSvgs: true))->bytes();

        self::assertStringContainsString('@font-face{font-family:"Inter";', $html);
    }

    public function test_no_font_faces_mode_uses_page_stack(): void
    {
        $warnings = [];
        $options = new HtmlOptions(fontMode: FontMode::None, inlineSvgs: true, onWarning: static function (string $w) use (&$warnings): void {
            $warnings[] = $w;
        });
        $html = Document::make(Page::a4()->body(self::fontSvg('Arial')->font('Cousine')))->toHtml($options)->bytes();

        self::assertSame('"Cousine", monospace', self::svgFamily($html));
        self::assertStringNotContainsString('@font-face', $html);
        self::assertSame([], array_values(array_filter($warnings, static fn (string $w): bool => str_contains($w, 'font'))));
    }
}
