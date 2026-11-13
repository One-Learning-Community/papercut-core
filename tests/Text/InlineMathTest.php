<?php

declare(strict_types=1);

namespace Papercut\Tests\Text;

use Papercut\{Equation, Span, Text};
use Papercut\Layout\Fragment;
use Papercut\Math\Math;
use Papercut\Paint\{Link as LinkOp, Op, TextRun};
use Papercut\Style\{ComputedStyle, Style, TextAlign, Theme};
use Papercut\Tests\Support\LayoutHarness;
use PHPUnit\Framework\TestCase;

final class InlineMathTest extends TestCase
{
    // The test font is 0.5em per character: a 12pt word of n characters is 6n points wide.

    private static function fragment(LayoutHarness $h, Text $text, float $width): Fragment
    {
        $f = $h->layout($text, $width)->fragment;
        self::assertInstanceOf(Fragment::class, $f);
        return $f;
    }

    /** @return list<TextRun> */
    private static function runs(Fragment $f): array
    {
        return array_values(array_filter($f->ops(), static fn (Op $o): bool => $o instanceof TextRun));
    }

    private static function mathWidth(LayoutHarness $h, \Papercut\Math\Node\Node $m): float
    {
        return Math::layout($m, Theme::default()->fontSize, false, $h->engine->context()->fonts->mathFont()['font'], false)->width;
    }

    public function test_inline_fraction_grows_line(): void
    {
        $h = new LayoutHarness();
        $frac = Span::math(Math::frac('1', '2'));
        $words = 'aaaa bbbb cccc dddd eeee ffff';
        $plain1 = self::fragment($h, Text::spans(Span::text('aaaa bbbb')), 400)->height;
        $plain2 = self::fragment($h, Text::spans(Span::text($words)), 100)->height;
        $with1 = self::fragment($h, Text::spans(Span::text('aaaa '), $frac, Span::text(' bbbb')), 400)->height;
        $with2 = self::fragment($h, Text::spans(Span::text('aaaa '), $frac, Span::text(' bbbb cccc dddd eeee ffff')), 100)->height;

        self::assertGreaterThan($plain1 + 1.0, $with1);
        self::assertEqualsWithDelta($with1 - $plain1, $with2 - $plain2, 1e-6, 'only the line with the formula grows');
    }

    public function test_baseline_shared(): void
    {
        $h = new LayoutHarness();
        $f = self::fragment($h, Text::spans(Span::text('ab '), Span::math('x'), Span::text(' cd')), 400);
        $runs = self::runs($f);
        $x = array_values(array_filter($runs, static fn (TextRun $r): bool => str_contains($r->text, '𝑥')));
        $ab = array_values(array_filter($runs, static fn (TextRun $r): bool => str_contains($r->text, 'ab')));
        $cd = array_values(array_filter($runs, static fn (TextRun $r): bool => str_contains($r->text, 'cd')));
        self::assertCount(1, $x);
        self::assertCount(1, $ab);
        self::assertCount(1, $cd);
        self::assertEqualsWithDelta($ab[0]->baseline, $x[0]->baseline, 1e-6);
        self::assertEqualsWithDelta($ab[0]->baseline, $cd[0]->baseline, 1e-6);
    }

    public function test_baseline_shared_with_a_tall_formula(): void
    {
        $h = new LayoutHarness();
        $f = self::fragment($h, Text::spans(Span::text('ab '), Span::math(Math::frac('1', '2'), '+ x'), Span::text(' cd')), 400);
        $runs = self::runs($f);
        $base = array_values(array_filter($runs, static fn (TextRun $r): bool => str_contains($r->text, 'ab')))[0]->baseline;
        $x = array_values(array_filter($runs, static fn (TextRun $r): bool => str_contains($r->text, '𝑥')))[0];
        self::assertEqualsWithDelta($base, $x->baseline, 1e-6);
        $plain = self::fragment($h, Text::spans(Span::text('ab cd')), 400);
        self::assertGreaterThan(self::runs($plain)[0]->baseline, $base, 'the line grew above the baseline');
    }

    public function test_never_breaks_inside(): void
    {
        $h = new LayoutHarness();
        $m = Math::row('x + y + z + w');
        $w = self::mathWidth($h, $m);
        self::assertGreaterThan(40.0, $w);
        $width = 54.0 + $w / 2; // the words fit; the formula does not fit beside them
        $f = self::fragment($h, Text::spans(Span::text('aaaa bbbb '), Span::math($m)), $width);
        $runs = self::runs($f);
        $word = array_values(array_filter($runs, static fn (TextRun $r): bool => str_contains($r->text, 'aaaa')))[0];
        $math = array_values(array_filter($runs, static fn (TextRun $r): bool => !str_contains($r->text, 'a') && !str_contains($r->text, 'b')));
        self::assertNotSame([], $math);
        foreach ($math as $r) {
            self::assertGreaterThan($word->baseline, $r->baseline, 'the whole formula is on the next line');
        }
        self::assertEqualsWithDelta($word->x, min(INF, ...array_map(static fn (TextRun $r): float => $r->x, $math)), 1e-6);
    }

    public function test_overlong_inline_overflows(): void
    {
        $h = new LayoutHarness();
        $m = Math::row('a + b + c + d + e + f + g + h');
        $w = self::mathWidth($h, $m);
        $width = $w / 2;
        $f = self::fragment($h, Text::spans(Span::text('ab '), Span::math($m), Span::text(' cd')), $width);
        $runs = self::runs($f);
        $ab = array_values(array_filter($runs, static fn (TextRun $r): bool => str_contains($r->text, 'ab')))[0];
        $cd = array_values(array_filter($runs, static fn (TextRun $r): bool => str_contains($r->text, 'cd')))[0];
        $math = array_values(array_filter($runs, static fn (TextRun $r): bool => $r !== $ab && $r !== $cd));
        self::assertNotSame([], $math);
        $baselines = array_unique(array_map(static fn (TextRun $r): float => round($r->baseline, 4), $math));
        self::assertCount(1, $baselines, 'one line, no break inside');
        self::assertGreaterThan($ab->baseline, $math[0]->baseline);
        self::assertGreaterThan($math[0]->baseline, $cd->baseline);
        self::assertGreaterThan($width, max(0.0, ...array_map(static fn (TextRun $r): float => $r->x, $math)), 'it overflows the line');
    }

    /**
     * "(formula)." has no space in it, so it is one chunk; when the formula is wider than the line the chunk is split and the
     * formula, which never splits, takes a line of its own. An opening bracket stays with it, and the closing text follows it.
     */
    public function test_overlong_formula_in_brackets_keeps_its_open_bracket(): void
    {
        $h = new LayoutHarness();
        $m = Math::row('a + b + c + d + e + f + g + h');
        $width = self::mathWidth($h, $m) / 2;
        $f = self::fragment($h, Text::spans(Span::text('ab '), Span::text('('), Span::math($m), Span::text(').'), Span::text(' cd')), $width);
        $runs = self::runs($f);
        $by = static fn (string $needle): TextRun => array_values(array_filter($runs, static fn (TextRun $r): bool => str_contains($r->text, $needle)))[0];
        $open = $by('(');
        $close = $by(').');
        $formula = array_values(array_filter($runs, static fn (TextRun $r): bool => !in_array($r, [$by('ab'), $open, $close], true)));

        self::assertNotSame([], $formula);
        self::assertCount(1, array_unique(array_map(static fn (TextRun $r): float => round($r->baseline, 4), $formula)), 'the formula stays whole');
        self::assertGreaterThan($by('ab')->baseline, $open->baseline);
        self::assertEqualsWithDelta($open->baseline, $formula[0]->baseline, 1e-6, 'the bracket is on the formula\'s line');
        self::assertLessThan($formula[0]->x, $open->x, 'and just before it');
        self::assertGreaterThan($formula[0]->baseline, $close->baseline, 'the closing text goes on after it, on the next line here');
    }

    public function test_justified_line_with_math(): void
    {
        $h = new LayoutHarness();
        $spans = [Span::text('aa bb '), Span::math('x'), Span::text(' cc dd ee ff gg hh ii jj kk')];
        $left = self::fragment($h, Text::spans(...$spans), 110);
        $just = self::fragment($h, Text::spans(...$spans)->textAlign(TextAlign::Justify), 110);
        $at = static fn (Fragment $f, string $needle): TextRun => array_values(array_filter(self::runs($f), static fn (TextRun $r): bool => str_contains($r->text, $needle)))[0];
        $x = static fn (Fragment $f): TextRun => array_values(array_filter(self::runs($f), static fn (TextRun $r): bool => str_contains($r->text, '𝑥')))[0];
        self::assertGreaterThan($x($left)->x, $x($just)->x, 'the gap before the formula stretched');
        self::assertGreaterThan($at($left, 'cc')->x, $at($just, 'cc')->x);
        self::assertEqualsWithDelta($x($left)->baseline, $x($just)->baseline, 1e-6);
    }

    public function test_inline_uses_compact_style(): void
    {
        $h = new LayoutHarness();
        $inline = self::fragment($h, Text::spans(Span::math(Math::frac('1', '2'))), 400);
        $display = $h->layout(Equation::of(Math::frac('1', '2')), 400)->fragment;
        self::assertInstanceOf(Fragment::class, $display);
        $size = static fn (Fragment $f): float => max(0.0, ...array_map(static fn (TextRun $r): float => $r->size, self::runs($f)));
        self::assertGreaterThan(0.0, $size($inline));
        self::assertLessThan($size($display), $size($inline), 'the numerator is at script size, below the display style one');
    }

    public function test_link_wraps_math(): void
    {
        $h = new LayoutHarness();
        $m = Math::row('x + y');
        $w = self::mathWidth($h, $m);
        $link = new Span('', Style::none(), 'https://x.test', math: $m);
        $f = self::fragment($h, Text::spans(Span::text('ab '), $link), 400);
        $links = array_values(array_filter($f->ops(), static fn (Op $o): bool => $o instanceof LinkOp));
        self::assertCount(1, $links);
        self::assertSame('https://x.test', $links[0]->uri);
        self::assertEqualsWithDelta(15.0, $links[0]->x, 1e-6, 'after "ab" and a space');
        self::assertEqualsWithDelta($w, $links[0]->width, 1e-6);
    }

    public function test_math_span_is_math(): void
    {
        $s = Span::math('x', Math::sup('y', '2'));
        self::assertNotNull($s->math);
        self::assertSame('', $s->text);
        self::assertNull(Span::text('x')->math);
    }

    public function test_math_survives_a_page_break(): void
    {
        $h = new LayoutHarness();
        $text = Text::spans(Span::math('x'), Span::text(' aaaa bbbb cccc dddd eeee ffff gggg hhhh iiii jjjj'));
        $whole = $h->layout($text, 60)->fragment;
        $cut = $h->layout($text, 60, 40);
        self::assertInstanceOf(Fragment::class, $whole);
        self::assertInstanceOf(Fragment::class, $cut->fragment);
        self::assertNotNull($cut->token, 'the paragraph broke');
        $glyphs = static fn (Fragment $f): int => count(array_filter(self::runs($f), static fn (TextRun $r): bool => str_contains($r->text, '𝑥')));
        self::assertSame(1, $glyphs($whole));
        self::assertSame(1, $glyphs($cut->fragment));
    }

    public function test_measure_only_layout_paints_math(): void
    {
        $h = new LayoutHarness();
        $h->engine->context()->measureOnly = true;
        $f = self::fragment($h, Text::spans(Span::text('ab '), Span::math('x')), 400);
        $x = array_values(array_filter(self::runs($f), static fn (TextRun $r): bool => str_contains($r->text, '𝑥')));
        self::assertCount(1, $x);
        self::assertGreaterThan(12.0, $x[0]->x, 'after the text, where the full layout puts it');
    }

    public function test_cell_of_only_math_has_a_minimum_as_wide_as_the_formula(): void
    {
        $h = new LayoutHarness();
        $m = Math::row('x + y + z');
        $w = self::mathWidth($h, $m);
        $min = $h->engine->breakingAnywhere(static fn (): array => $h->engine->intrinsic(Text::spans(Span::math($m)), ComputedStyle::root(Theme::default())))['min'];
        self::assertGreaterThanOrEqual($w - 1e-6, $min);
    }
}
