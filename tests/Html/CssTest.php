<?php

declare(strict_types=1);

namespace Papercut\Tests\Html;

use Papercut\Html\Css;
use Papercut\Text\{FontClass, FontSet};
use Papercut\Style\{Align, BorderStyle, ComputedStyle, Direction, Justify, Style, TextAlign, Theme};
use Papercut\Value\{Auto, Color, Edges, Length, Percent};
use PHPUnit\Framework\TestCase;

final class CssTest extends TestCase
{
    private function root(): ComputedStyle
    {
        return ComputedStyle::root(Theme::default());
    }

    private function child(Style $style, ?ComputedStyle $parent = null): ComputedStyle
    {
        return ($parent ?? $this->root())->child($style);
    }

    public function test_num_formatting(): void
    {
        self::assertSame('12', Css::num(12.0));
        self::assertSame('0.333', Css::num(0.3333));
        self::assertSame('0', Css::num(-0.0));
        self::assertSame('0', Css::num(-0.0001));
        self::assertSame('1.5', Css::num(1.5));
        self::assertSame('12pt', Css::pt(12.0));
    }

    public function test_length(): void
    {
        self::assertSame('10pt', Css::length(Length::parse('10pt')));
        self::assertSame('50%', Css::length(Percent::of(50.0)));
        self::assertNull(Css::length(Auto::instance()));
    }

    public function test_color(): void
    {
        self::assertSame('#0a1b2c', Css::color(Color::rgb(10, 27, 44)));
        self::assertSame('rgba(17, 34, 51, 0.502)', Css::color(Color::hex('#11223380')));
        self::assertSame('rgba(0, 0, 0, 0)', Css::color(Color::transparent()));
    }

    public function test_string_escapes_quotes_backslashes_and_control_chars(): void
    {
        self::assertSame('"Inter"', Css::string('Inter'));
        $evil = Css::string('Evil"; background:url(x)');
        self::assertSame('"Evil\\"; background:url(x)"', $evil);
        self::assertSame('"a\\\\b"', Css::string('a\\b'));
        self::assertSame('"a\\a b"', Css::string("a\nb"));
        self::assertSame('"a\\d b"', Css::string("a\rb"));
        self::assertSame('"\\0 "', Css::string("\0"));
        self::assertSame('"\\3c /style>"', Css::string('</style>'));
        self::assertSame('"Zürich"', Css::string('Zürich'));
        self::assertSame('"Evil\\"; background:url(x)", system-ui, sans-serif', Css::fontFamily('Evil"; background:url(x)'));
    }

    public function test_mono_family_stack_ends_in_monospace(): void
    {
        self::assertSame('"Cousine", monospace', Css::fontFamily('Cousine', [], FontClass::Mono));
        $fonts = new FontSet(FontSet::bundled());
        $s = $this->child(new Style(fontFamily: 'Cousine'));
        self::assertSame('"Cousine", monospace', Css::text($s, null, [], $fonts)['font-family']);
    }

    public function test_serif_class_ends_in_serif(): void
    {
        self::assertSame('"X", serif', Css::fontFamily('X', [], FontClass::Serif));
    }

    public function test_fallbacks_precede_the_class_generic(): void
    {
        self::assertSame('"Cousine", "Inter", monospace', Css::fontFamily('Cousine', ['Inter'], FontClass::Mono));
    }

    public function test_sans_stack_unchanged(): void
    {
        self::assertSame('"Inter", system-ui, sans-serif', Css::fontFamily('Inter', [], FontClass::Sans));
        $fonts = new FontSet(FontSet::bundled());
        self::assertSame('"Inter", system-ui, sans-serif', Css::text($this->root(), null, [], $fonts)['font-family']);
    }

    public function test_unknown_family_keeps_sans_fallback(): void
    {
        self::assertSame('"Nope", system-ui, sans-serif', Css::fontFamily('Nope'));
        $s = $this->child(new Style(fontFamily: 'Nope'));
        self::assertSame('"Nope", system-ui, sans-serif', Css::text($s, null, [], new FontSet(FontSet::bundled()))['font-family']);
    }

    public function test_box_omits_defaults_and_emits_borders_only_with_width(): void
    {
        self::assertSame([], Css::box($this->child(new Style())));

        $s = $this->child(new Style(
            width: Length::parse('100pt'),
            maxWidth: Percent::of(80.0),
            padding: Edges::of(Length::parse('1pt'), Length::parse('2pt')),
            margin: Edges::all(Length::parse('3pt')),
            radius: Length::parse('4pt'),
            background: Color::hex('#ff0000'),
            opacity: 0.5,
        ));
        self::assertSame([
            'width' => '100pt',
            'max-width' => '80%',
            'padding' => '1pt 2pt 1pt 2pt',
            'margin' => '3pt 3pt 3pt 3pt',
            'border-radius' => '4pt',
            'background' => '#ff0000',
            'opacity' => '0.5',
        ], Css::box($s));

        $b = $this->child(new Style(borderWidth: Edges::of(Length::parse('1pt'), Length::zero()), borderColor: Color::hex('#00ff00'), borderStyle: BorderStyle::Dashed));
        $box = Css::box($b);
        self::assertSame('1pt 0pt 1pt 0pt', $box['border-width']);
        self::assertSame('dashed', $box['border-style']);
        self::assertSame('#00ff00', $box['border-color']);

        $noWidth = Css::box($this->child(new Style(borderColor: Color::hex('#00ff00'), borderStyle: BorderStyle::Dashed)));
        self::assertSame([], $noWidth);
    }

    public function test_text_diffs_against_parent(): void
    {
        $parent = $this->root();
        $all = Css::text($parent, null);
        foreach (['font-family', 'font-size', 'font-weight', 'font-style', 'color', 'line-height', 'letter-spacing', 'text-align', 'text-decoration'] as $k) {
            self::assertArrayHasKey($k, $all);
        }
        self::assertSame('normal', $all['font-style']);
        self::assertSame('none', $all['text-decoration']);

        $child = $this->child(new Style(color: Color::hex('#ff0000')), $parent);
        self::assertSame(['color' => '#ff0000'], Css::text($child, $parent));

        $rich = $this->child(new Style(italic: true, underline: true, strike: true, textAlign: TextAlign::Center, letterSpacing: Length::parse('1pt'), fontWeight: 700), $parent);
        $d = Css::text($rich, $parent);
        self::assertSame('italic', $d['font-style']);
        self::assertSame('underline line-through', $d['text-decoration']);
        self::assertSame('center', $d['text-align']);
        self::assertSame('1pt', $d['letter-spacing']);
        self::assertSame('700', $d['font-weight']);
        self::assertSame(['text-decoration' => 'underline'], Css::text($this->child(new Style(underline: true), $parent), $parent));
        self::assertSame(['text-decoration' => 'line-through'], Css::text($this->child(new Style(strike: true), $parent), $parent));
    }

    public function test_flex_maps_enums(): void
    {
        self::assertSame(['display' => 'flex', 'flex-direction' => 'column'], Css::flexContainer($this->child(new Style())));

        $s = $this->child(new Style(direction: Direction::Row, gap: Length::parse('6pt'), justify: Justify::SpaceBetween, alignItems: Align::Center, wrap: true));
        self::assertSame([
            'display' => 'flex',
            'flex-direction' => 'row',
            'gap' => '6pt',
            'justify-content' => 'space-between',
            'align-items' => 'center',
            'flex-wrap' => 'wrap',
        ], Css::flexContainer($s));

        $map = [Justify::Start->value => 'flex-start', Justify::End->value => 'flex-end', Justify::Center->value => 'center', Justify::SpaceBetween->value => 'space-between', Justify::SpaceAround->value => 'space-around', Justify::SpaceEvenly->value => 'space-evenly'];
        foreach (Justify::cases() as $j) {
            if ($j === Justify::Start) {
                continue;
            }
            $d = Css::flexContainer($this->child(new Style(justify: $j)));
            self::assertSame($map[$j->value], $d['justify-content']);
        }
        self::assertSame('flex-start', Css::flexContainer($this->child(new Style(alignItems: Align::Start)))['align-items']);
        self::assertSame('flex-end', Css::flexContainer($this->child(new Style(alignItems: Align::End)))['align-items']);

        self::assertSame([], Css::flexItem($this->child(new Style())));
        self::assertSame(['flex' => '1 1 auto'], Css::flexItem($this->child(new Style(grow: 1.0))));
        self::assertSame(['flex' => '0 0 30%', 'align-self' => 'flex-end'], Css::flexItem($this->child(new Style(shrink: 0.0, basis: Percent::of(30.0), alignSelf: Align::End))));
        self::assertSame(['align-self' => 'stretch'], Css::flexItem($this->child(new Style(alignSelf: Align::Stretch))));
    }

    public function test_pagination_maps_keep_and_breaks(): void
    {
        self::assertSame([], Css::pagination($this->child(new Style())));
        $s = $this->child(new Style(breakBefore: true, breakAfter: true, keepTogether: true, minLines: 5));
        self::assertSame([
            'break-before' => 'page',
            'break-after' => 'page',
            'break-inside' => 'avoid',
            'orphans' => '5',
            'widows' => '5',
        ], Css::pagination($s));

        $parent = $this->child(new Style(minLines: 5));
        self::assertSame([], Css::pagination($this->child(new Style(), $parent), $parent));
        self::assertSame(['orphans' => '1', 'widows' => '1'], Css::pagination($this->child(new Style(minLines: 1), $parent), $parent));
    }

    public function test_attr(): void
    {
        self::assertSame('', Css::attr([]));
        self::assertSame('a:1;b:2', Css::attr(['a' => '1', 'b' => '2']));
    }
}
