<?php

declare(strict_types=1);

namespace Papercut\Tests\Html;

use Papercut\Html\{Css, InlineSvg};
use PHPUnit\Framework\TestCase;

final class InlineSvgTest extends TestCase
{
    private const string NS = 'xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"';

    /**
     * @param array<string, string> $style
     * @return array{html: string, approximated: list<string>}
     */
    private static function write(string $inner, string $alt = '', array $style = [], string $rootAttrs = ''): array
    {
        $xml = '<svg ' . self::NS . ' viewBox="0 0 10 10" width="10pt" height="10pt"' . $rootAttrs . '>' . $inner . '</svg>';

        return (new InlineSvg())->write($xml, 'pc-s1-', $alt, $style);
    }

    public function test_ids_are_prefixed_and_references_rewritten(): void
    {
        $r = self::write('<linearGradient id="g"><stop offset="0" stop-color="red"/></linearGradient>'
            . '<rect width="5" height="5" fill="url(#g)" style="stroke:url(#g)"/><use href="#g"/><use xlink:href="#g"/>'
            . '<rect fill="url(\'#g\')" width="1" height="1"/>');

        self::assertSame(1, substr_count($r['html'], 'id="pc-s1-g"'));
        self::assertSame(5, substr_count($r['html'], 'pc-s1-g') - 1);
        self::assertStringNotContainsString('#g', str_replace('#pc-s1-g', '', $r['html']));
    }

    public function test_unknown_references_are_left_alone(): void
    {
        $r = self::write('<rect id="a" width="1" height="1" fill="url(#other)"/><use href="#nope"/>');

        self::assertStringContainsString('url(#other)', $r['html']);
        self::assertStringContainsString('href="#nope"', $r['html']);
        self::assertStringContainsString('id="pc-s1-a"', $r['html']);
    }

    public function test_style_selectors_are_scoped(): void
    {
        $r = self::write('<linearGradient id="g"/><style>.a{fill:red} text{font-size:9px} svg{color:blue} .b,.c{fill:url(#g)}</style><rect class="a"/>');

        self::assertStringContainsString('class="pc-s1"', $r['html']);
        self::assertStringContainsString('.pc-s1 .a{fill:red}', str_replace([' {', '{ '], ['{', '{'], $r['html']));
        self::assertStringContainsString('.pc-s1 text', $r['html']);
        self::assertStringContainsString('svg.pc-s1', $r['html']);
        self::assertStringContainsString('.pc-s1 .b', $r['html']);
        self::assertStringContainsString('.pc-s1 .c', $r['html']);
        self::assertStringContainsString('url(#pc-s1-g)', $r['html']);
        self::assertSame([], $r['approximated']);
    }

    public function test_existing_root_class_is_kept(): void
    {
        $r = self::write('<style>.a{fill:red}</style>', '', [], ' class="logo"');

        self::assertStringContainsString('class="logo pc-s1"', $r['html']);
    }

    public function test_cdata_style_is_scoped_the_same_way(): void
    {
        $r = self::write('<style><![CDATA[.a > b{fill:red}]]></style>');

        self::assertStringContainsString('.pc-s1 .a > b', $r['html']);
    }

    public function test_unscopable_at_rule_is_dropped_and_reported(): void
    {
        $r = self::write('<style>@import url(x.css); @media print{.a{fill:red}} @font-face{font-family:X;src:url(x.woff)} .b{fill:blue}</style>');

        self::assertStringNotContainsString('@import', $r['html']);
        self::assertSame(["inline SVG <style> @import removed (can't be scoped)"], $r['approximated']);
        self::assertStringContainsString('@media print', $r['html']);
        self::assertStringContainsString('.pc-s1 .a', $r['html']);
        self::assertStringContainsString('@font-face', $r['html']);
        self::assertStringContainsString('.pc-s1 .b', $r['html']);
    }

    public function test_one_entry_per_dropped_kind(): void
    {
        $r = self::write('<style>@import url(a.css); @import url(b.css); @supports (x:y){.a{fill:red}}</style>');

        self::assertSame(["inline SVG <style> @import removed (can't be scoped)", "inline SVG <style> @supports removed (can't be scoped)"], $r['approximated']);
    }

    public function test_alt_and_decorative(): void
    {
        $named = self::write('<rect width="1" height="1"/>', 'Line')['html'];
        self::assertStringContainsString('role="img"', $named);
        self::assertStringContainsString('aria-label="Line"', $named);

        $decor = self::write('<rect width="1" height="1"/>', '')['html'];
        self::assertStringContainsString('aria-hidden="true"', $decor);
        self::assertStringNotContainsString('role=', $decor);
    }

    public function test_box_style_is_on_the_root(): void
    {
        $html = self::write('<rect width="1" height="1"/>', '', ['width' => '100pt', 'display' => 'block'])['html'];

        self::assertMatchesRegularExpression('/^<svg[^>]*style="[^"]*display:\s*block[^"]*width:\s*100pt/', $html);
    }

    public function test_output_is_an_svg_element_without_xml_declaration(): void
    {
        $html = self::write('<rect width="1" height="1"/>')['html'];

        self::assertStringStartsWith('<svg', $html);
        self::assertStringNotContainsString('<?xml', $html);
    }

    public function test_breakout_text_in_css_stays_text(): void
    {
        $html = self::write('<style>p{content:"&lt;/style&gt;&lt;img src=x onerror=alert(1)&gt;"}</style><rect width="1" height="1"/>')['html'];
        $doc = \Dom\HTMLDocument::createFromString('<!doctype html><body>' . $html . '<p id="x">z</p></body>', LIBXML_NOERROR);

        self::assertSame(0, $doc->getElementsByTagName('img')->length);
        self::assertNotNull($doc->getElementById('x'));
    }

    public function test_aria_lists_split_on_any_whitespace(): void
    {
        $html = self::write('<title id="t">T</title><desc id="d">D</desc><g aria-describedby="t&#10;d&#9;t"/>')['html'];

        self::assertMatchesRegularExpression('/aria-describedby="pc-s1-t pc-s1-d pc-s1-t"/', $html);
    }

    public function test_smil_offsets_and_digit_names_are_not_rewritten(): void
    {
        $html = self::write('<rect id="r" width="1" height="1"/><animate begin="2.5s" end="r.click" attributeName="x"/>')['html'];

        self::assertStringContainsString('begin="2.5s"', $html);
        self::assertStringContainsString('end="pc-s1-r.click"', $html);

        $digits = self::write('<rect id="2" width="1" height="1"/><animate begin="2.5s" attributeName="x"/>')['html'];
        self::assertStringContainsString('begin="2.5s"', $digits);
    }

    public function test_selectors_that_target_the_root_still_match_it(): void
    {
        $html = self::write('<style>.logo{fill:red} #r{fill:blue} :root{fill:green} svg .a{x:y} svg{z:z}</style><svg><rect class="a"/></svg>', '', [], ' class="logo" id="r"')['html'];

        self::assertStringContainsString('.pc-s1.logo', $html);
        self::assertStringContainsString('.pc-s1 .logo', $html);
        self::assertStringContainsString('.pc-s1#pc-s1-r', $html);
        self::assertStringContainsString('svg.pc-s1{fill:green}', $html);
        self::assertStringNotContainsString(':root', $html);
        self::assertStringContainsString('svg.pc-s1 .a', $html);
        self::assertStringContainsString('.pc-s1 svg', $html);
    }

    public function test_idref_attributes_are_rewritten(): void
    {
        $html = self::write('<title id="t">T</title><desc id="d">D</desc><rect id="r" width="1" height="1" aria-describedby="d missing" aria-controls="r"/>'
            . '<animate id="an" begin="r.click+1s; an.end; 2s" end="r.begin" attributeName="x"/>'
            . '<set begin="missing.click" attributeName="y"/>', '')['html'];

        self::assertStringContainsString('aria-describedby="pc-s1-d missing"', $html);
        self::assertStringContainsString('aria-controls="pc-s1-r"', $html);
        self::assertStringContainsString('begin="pc-s1-r.click+1s; pc-s1-an.end; 2s"', $html);
        self::assertStringContainsString('end="pc-s1-r.begin"', $html);
        self::assertStringContainsString('begin="missing.click"', $html);
    }

    public function test_root_labelledby_is_dropped_when_alt_is_set_and_rewritten_otherwise(): void
    {
        $inner = '<title id="t">T</title>';
        $named = self::write($inner, 'Alt', [], ' aria-labelledby="t"')['html'];
        self::assertStringNotContainsString('aria-labelledby', $named);
        self::assertStringContainsString('aria-label="Alt"', $named);

        $decor = self::write($inner, '', [], ' aria-labelledby="t"')['html'];
        self::assertStringContainsString('aria-hidden="true"', $decor);
        self::assertStringContainsString('aria-labelledby="pc-s1-t"', $decor);

        $inner2 = self::write('<g aria-labelledby="t u"/><title id="t">T</title>', '')['html'];
        self::assertStringContainsString('aria-labelledby="pc-s1-t u"', $inner2);
    }

    /** @param array<string, string> $style */
    private static function writeFamilies(string $inner, array $style = [], string $rootAttrs = ''): string
    {
        $xml = '<svg ' . self::NS . ' viewBox="0 0 10 10"' . $rootAttrs . '>' . $inner . '</svg>';
        $family = static fn (?string $list, int $weight, bool $italic): string => 'FAMILY(' . ($list ?? '-') . '|' . $weight . '|' . ($italic ? 'i' : 'n') . ')';

        return (new InlineSvg($family))->write($xml, 'pc-s1-', '', $style)['html'];
    }

    public function test_font_families_are_rewritten(): void
    {
        $html = self::writeFamilies(
            '<text font-family="Arial" font-weight="bold">a</text>'
            . '<text style="font-family:\'Open Sans\'; fill:red">b</text>'
            . '<g font-family="X" font-weight="700"><text>c</text><text font-family="Z" font-style="italic">d</text></g>'
            . '<style>text{font-family:Y}</style>',
        );

        self::assertStringContainsString('font-family="FAMILY(Arial|700|n)"', $html);
        self::assertStringContainsString('font-family:FAMILY(\'Open Sans\'|400|n)', $html);
        self::assertStringContainsString('fill:red', $html);
        self::assertStringContainsString('<g font-family="FAMILY(X|700|n)"', $html);
        self::assertStringContainsString('font-family="FAMILY(Z|700|i)"', $html);
        self::assertStringContainsString('font-family:FAMILY(Y|400|n)', $html);
        self::assertStringNotContainsString('Arial"', str_replace('FAMILY(Arial|700|n)', '', $html));
    }

    public function test_weight_and_style_come_from_the_nearest_declaration(): void
    {
        $html = self::writeFamilies(
            '<g font-weight="bold"><text font-weight="normal" font-family="A">a</text><text style="font-weight:600;font-style:oblique;font-family:B">b</text></g>'
            . '<style>.t{font-weight:bold;font-style:italic;font-family:C}.u{font-family:D}</style>',
        );

        self::assertStringContainsString('FAMILY(A|400|n)', $html);
        self::assertStringContainsString('FAMILY(B|700|i)', $html);
        self::assertStringContainsString('FAMILY(C|700|i)', $html);
        self::assertStringContainsString('FAMILY(D|400|n)', $html);
    }

    public function test_the_root_gets_the_default_family_unless_it_sets_one(): void
    {
        $plain = self::writeFamilies('<text>a</text>', ['width' => '10pt']);
        self::assertSame(1, substr_count($plain, 'FAMILY(-|400|n)'));

        $own = self::writeFamilies('<text>a</text>', [], ' font-family="Mine"');
        self::assertStringContainsString('font-family="FAMILY(Mine|400|n)"', $own);
        self::assertStringNotContainsString('FAMILY(-', $own);
    }

    public function test_font_face_rules_in_the_svg_are_left_alone(): void
    {
        $html = self::writeFamilies('<style>@font-face{font-family:Mine;src:url(x.woff)}text{font-family:Mine}</style><text>a</text>');

        self::assertStringContainsString('@font-face{font-family:Mine;src:url(x.woff)}', $html);
        self::assertStringContainsString('text{font-family:FAMILY(Mine|400|n)}', $html);
    }

    public function test_families_are_left_alone_without_a_closure(): void
    {
        $r = self::write('<text font-family="Arial" style="font-family:Arial">a</text><style>text{font-family:Arial}</style>');

        self::assertSame(3, substr_count($r['html'], 'Arial'));
    }

    public function test_only_foreign_object_or_unreadable_xml_blocks_inlining(): void
    {
        $ns = 'xmlns="http://www.w3.org/2000/svg"';

        self::assertNull(InlineSvg::inlinable('<svg ' . $ns . '><title>t<b/></title><p/><rect/></svg>'));
        self::assertSame("<foreignObject> can't be written inline", InlineSvg::inlinable('<svg ' . $ns . '><g><foreignObject/></g></svg>'));
        self::assertSame("<foreignObject> can't be written inline", InlineSvg::inlinable('<svg ' . $ns . '><foreignobject/></svg>'));
        self::assertSame('the SVG cannot be read', InlineSvg::inlinable('<svg'));
    }

    public function test_style_property_names_match_in_any_case(): void
    {
        $html = self::writeFamilies('<style>text{FONT-FAMILY:Y; Font-Weight:bold}</style><text style="FONT-FAMILY:Z">a</text>');

        self::assertStringContainsString('FAMILY(Y|700|n)', $html);
        self::assertStringContainsString('FAMILY(Z|400|n)', $html);
        self::assertStringNotContainsString('Y;', $html);
    }

    public function test_the_font_shorthand_has_its_family_rewritten(): void
    {
        $html = self::writeFamilies(
            '<text style="font: italic bold 12px/1.5 \'Open Sans\', Arial; fill:red">a</text>'
            . '<text style="font:10pt Mono">b</text>'
            . '<style>.t{font: 700 9px Lora, serif}.u{font:menu}</style>',
        );

        self::assertStringContainsString('font:italic bold 12px/1.5 FAMILY(\'Open Sans\', Arial|700|i)', str_replace('font: ', 'font:', $html));
        self::assertStringContainsString('fill:red', $html);
        self::assertStringContainsString('font:10pt FAMILY(Mono|400|n)', $html);
        self::assertStringContainsString('font:700 9px FAMILY(Lora, serif|700|n)', $html);
        self::assertStringContainsString('.u{font:menu}', $html);
    }

    public function test_the_root_default_family_is_not_asked_for_without_text(): void
    {
        $asked = [];
        $family = static function (?string $list, int $weight, bool $italic) use (&$asked): string {
            $asked[] = $list;

            return 'F';
        };
        $xml = '<svg ' . self::NS . ' viewBox="0 0 10 10"><rect width="1" height="1"/></svg>';
        $out = (new InlineSvg($family))->write($xml, 'pc-s1-', '', [])['html'];

        self::assertSame([], $asked);
        self::assertStringNotContainsString('font-family', $out);
    }

    public function test_a_family_name_with_quotes_or_markup_stays_inside_the_svg(): void
    {
        $name = 'Evil"</style><img src=x onerror=alert(1)>';
        $family = static fn (?string $list, int $weight, bool $italic): string => Css::fontFamily($name);
        $xml = '<svg ' . self::NS . ' viewBox="0 0 10 10"><style>text{font-family:Q}</style><text font-family="A">a</text><text style="font-family:B">b</text></svg>';
        $html = (new InlineSvg($family))->write($xml, 'pc-s1-', '', [])['html'];
        $doc = \Dom\HTMLDocument::createFromString('<!doctype html><body>' . $html . '<p id="after">x</p>', LIBXML_NOERROR);

        self::assertSame('BODY', $doc->getElementById('after')?->parentNode?->nodeName);
        self::assertSame(0, $doc->getElementsByTagName('img')->length);
        self::assertSame(1, $doc->getElementsByTagName('style')->length);
        self::assertSame(2, $doc->getElementsByTagName('text')->length);
        self::assertStringContainsString('\\"', $html);
    }

    public function test_root_shrinks_to_its_container_and_keeps_its_ratio(): void
    {
        $html = self::write('<rect width="1" height="1"/>')['html'];

        self::assertMatchesRegularExpression('/style="[^"]*display:\s*block;[^"]*max-width:\s*100%;[^"]*height:\s*auto/', $html);
        self::assertStringContainsString('width="10pt"', $html);
    }

    public function test_a_box_width_alone_drops_the_height_attribute_and_a_height_alone_the_width(): void
    {
        $w = self::write('<rect width="1" height="1"/>', '', ['width' => '100pt'])['html'];
        self::assertStringNotContainsString(' height="10pt"', $w);
        self::assertStringContainsString('width:100pt', $w);
        self::assertStringNotContainsString('height:100', $w);
        self::assertStringContainsString('height:auto', $w);

        $h = self::write('<rect width="1" height="1"/>', '', ['height' => '50pt'])['html'];
        self::assertStringNotContainsString(' width="10pt"', $h);
        self::assertStringContainsString('height:50pt', $h);
        self::assertStringNotContainsString('height:auto', $h);

        $both = self::write('<rect width="1" height="1"/>', '', ['width' => '40pt', 'height' => '50pt'])['html'];
        self::assertStringContainsString('height:50pt', $both);
    }

    public function test_an_svg_without_a_namespace_keeps_its_content(): void
    {
        $xml = '<svg viewBox="0 0 10 10" width="10" height="10"><g id="a"><rect width="10" height="10" fill="red"/></g></svg>';
        $html = (new InlineSvg())->write($xml, 'pc-x-', 'alt', [])['html'];

        self::assertStringContainsString('<rect', $html);
        self::assertStringContainsString('<g id="pc-x-a"', $html);
        self::assertNull(InlineSvg::inlinable($xml));
        $doc = \Dom\HTMLDocument::createFromString('<!doctype html><body>' . $html, LIBXML_NOERROR);
        self::assertSame(1, $doc->getElementsByTagName('rect')->length);
    }

    public function test_a_prefixed_svg_namespace_is_written_unprefixed(): void
    {
        $xml = '<svg:svg xmlns:svg="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><svg:rect width="1" height="1"/></svg:svg>';
        $html = (new InlineSvg())->write($xml, 'pc-x-', '', [])['html'];

        self::assertStringStartsWith('<svg', $html);
        self::assertStringContainsString('<rect', $html);
        self::assertStringNotContainsString('svg:', $html);
    }

    public function test_keyframes_are_kept_with_their_names_prefixed_and_references_rewritten(): void
    {
        $css = '.a{animation:spin 2s linear infinite}.b{animation-name:spin,fade}'
            . '@keyframes spin{from{transform:rotate(0)}to{transform:rotate(360deg)}}'
            . '@-webkit-keyframes fade{0%{opacity:0}100%{opacity:1}}@supports (x:y){.c{fill:red}}';
        $r = self::write('<style>' . $css . '</style><rect class="a" style="animation: spin 1s" width="1" height="1"/>');
        $flat = $r['html'];

        self::assertStringContainsString('@keyframes pc-s1-spin{', $flat);
        self::assertStringContainsString('@-webkit-keyframes pc-s1-fade{', $flat);
        self::assertStringContainsString('animation:pc-s1-spin 2s linear infinite', $flat);
        self::assertStringContainsString('animation-name:pc-s1-spin,pc-s1-fade', $flat);
        self::assertStringContainsString('style="animation: pc-s1-spin 1s"', $flat);
        self::assertSame(['inline SVG <style> @supports removed (can\'t be scoped)'], $r['approximated']);
    }

    public function test_bold_and_italic_variants_used_below_a_family_are_asked_for(): void
    {
        $asked = [];
        $family = static function (?string $list, int $weight, bool $italic) use (&$asked): string {
            $asked[] = ($list ?? '-') . '|' . $weight . '|' . ($italic ? 'i' : 'n');

            return 'F';
        };
        $xml = '<svg ' . self::NS . ' viewBox="0 0 10 10"><g font-family="Foo"><text>a<tspan font-weight="bold">b</tspan></text>'
            . '<text font-style="italic">c</text></g><text><tspan style="font-weight:700">d</tspan></text></svg>';
        (new InlineSvg($family))->write($xml, 'pc-s1-', '', []);

        self::assertContains('Foo|400|n', $asked);
        self::assertContains('Foo|700|n', $asked);
        self::assertContains('Foo|400|i', $asked);
        self::assertContains('-|700|n', $asked);
    }

    public function test_the_roots_own_weight_and_style_reach_the_default_family(): void
    {
        $asked = [];
        $family = static function (?string $list, int $weight, bool $italic) use (&$asked): string {
            $asked[] = ($list ?? '-') . '|' . $weight . '|' . ($italic ? 'i' : 'n');

            return 'F';
        };
        $xml = '<svg ' . self::NS . ' viewBox="0 0 10 10" font-weight="bold" font-style="italic"><text>a</text></svg>';
        (new InlineSvg($family))->write($xml, 'pc-s1-', '', []);

        self::assertContains('-|700|i', $asked);
        self::assertNotContains('-|400|n', $asked);
    }

    public function test_a_rewritten_style_attribute_keeps_its_trailing_semicolon(): void
    {
        $r = self::write('<style>@keyframes spin{to{opacity:0}}</style><rect width="1" height="1" style="animation-name:spin;"/>', '', []);

        self::assertStringContainsString('animation-name:pc-s1-spin;"', $r['html']);
    }

    public function test_a_root_content_attribute_is_dropped(): void
    {
        $r = self::write('<rect width="1" height="1"/>', '', [], ' content="&lt;mxfile&gt;huge&lt;/mxfile&gt;"');

        self::assertStringNotContainsString('mxfile', $r['html']);
        self::assertStringNotContainsString('content=', $r['html']);
    }
}
