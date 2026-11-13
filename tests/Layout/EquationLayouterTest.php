<?php

declare(strict_types=1);

namespace Papercut\Tests\Layout;

use Papercut\{Equation, Text, View};
use Papercut\Layout\{Fragment, Status};
use Papercut\Math\Math;
use Papercut\Paint\{DrawSvg, Op, Rect, TextRun};
use Papercut\Style\{Theme, TextAlign};
use Papercut\Tests\Support\{FakeBackend, LayoutHarness};
use Papercut\Text\FontRegistry;
use Papercut\Value\Color;
use PHPUnit\Framework\TestCase;
use function Papercut\pt;

final class EquationLayouterTest extends TestCase
{
    private static function quadratic(): Equation
    {
        return Equation::of('x =', Math::frac(Math::row('−b ±', Math::sqrt(Math::sup('b', '2'), '− 4ac')), '2a'));
    }

    /** @return list<Op> every op in the tree, offsets ignored */
    private static function ops(Fragment $f): array
    {
        $out = $f->ops();
        foreach ($f->children() as $c) {
            array_push($out, ...self::ops($c['fragment']));
        }
        return $out;
    }

    /** @return list<array{x: float, op: Op}> every op in the tree with its absolute x */
    private static function placed(Fragment $f, float $ox = 0.0): array
    {
        $out = [];
        foreach ($f->ops() as $op) {
            if ($op instanceof TextRun || $op instanceof Rect || $op instanceof DrawSvg) {
                $out[] = ['x' => $ox + $op->x, 'op' => $op];
            }
        }
        foreach ($f->children() as $c) {
            array_push($out, ...self::placed($c['fragment'], $ox + $c['x']));
        }
        return $out;
    }

    public function test_quadratic_ops(): void
    {
        $h = new LayoutHarness();
        $f = $h->layout(self::quadratic(), 400)->fragment;
        $this->assertNotNull($f);
        $ops = self::ops($f);
        $rects = array_values(array_filter($ops, static fn (Op $o): bool => $o instanceof Rect && $o->fill !== null));
        $this->assertCount(2, $rects, 'a fraction bar and a radical overbar');
        $text = implode('', array_map(static fn (TextRun $r): string => $r->text, array_values(array_filter($ops, static fn (Op $o): bool => $o instanceof TextRun))));
        $this->assertStringContainsString('𝑥', $text);
        $this->assertStringContainsString('4', $text);
    }

    public function test_height_is_box_height(): void
    {
        $h = new LayoutHarness();
        $eq = self::quadratic();
        $f = $h->layout($eq, 400)->fragment;
        $this->assertNotNull($f);
        $box = Math::layout($eq->math(), Theme::default()->fontSize, true, $h->engine->context()->fonts->mathFont()['font'], false);
        $this->assertEqualsWithDelta($box->ascent + $box->descent, $f->height, 0.01);
        // The margin below belongs to the parent, which puts the style's 6pt under each equation.
        $column = $h->layout(View::make($eq, $eq), 400)->fragment;
        $this->assertNotNull($column);
        $this->assertEqualsWithDelta(2 * ($box->ascent + $box->descent) + 12.0, $column->height, 0.01);
    }

    public function test_max_and_min_width_bound_the_box(): void
    {
        $h = new LayoutHarness();
        $eq = Equation::of('x = 1');

        $this->assertEqualsWithDelta(150.0, $h->layout($eq->maxWidth(pt(150)), 400)->fragment?->width, 0.01);
        $this->assertEqualsWithDelta(200.0, $h->layout($eq->minWidth(pt(200))->width(pt(100)), 400)->fragment?->width, 0.01);
        $this->assertEqualsWithDelta(100.0, $h->layout($eq->maxWidth(\Papercut\Value\Percent::of(25)), 400)->fragment?->width, 0.01);
    }

    public function test_alignment(): void
    {
        $h = new LayoutHarness();
        $eq = Equation::of('x = 1');
        $left = $h->layout($eq, 300)->fragment;
        $center = $h->layout($eq->textAlign(TextAlign::Center), 300)->fragment;
        $right = $h->layout($eq->textAlign(TextAlign::Right), 300)->fragment;
        $this->assertNotNull($left);
        $this->assertNotNull($center);
        $this->assertNotNull($right);
        $box = Math::layout($eq->math(), Theme::default()->fontSize, true, $h->engine->context()->fonts->mathFont()['font'], false);
        $first = static fn (Fragment $f): float => min([1e9, ...array_map(static fn (array $p): float => $p['x'], self::placed($f))]);
        $this->assertEqualsWithDelta((300 - $box->width) / 2, $first($center) - $first($left), 0.01);
        $this->assertEqualsWithDelta(300 - $box->width, $first($right) - $first($left), 0.01);
    }

    public function test_does_not_split(): void
    {
        $h = new LayoutHarness();
        $r = $h->layout(self::quadratic(), 400, 10.0, null, false);
        $this->assertSame(Status::DoesNotFit, $r->status);
    }

    public function test_wider_than_box_overflows(): void
    {
        $h = new LayoutHarness();
        $r = $h->layout(self::quadratic(), 40);
        $this->assertSame(Status::Complete, $r->status);
        $this->assertNotNull($r->fragment);
        $right = max([0.0, ...array_map(static fn (array $p): float => $p['op'] instanceof TextRun ? $p['x'] : 0.0, self::placed($r->fragment))]);
        $this->assertGreaterThan(40.0, $right);
    }

    public function test_takes_text_colour_and_size(): void
    {
        $h = new LayoutHarness();
        $red = Color::hex('#ff0000');
        $eq = Equation::of(self::quadratic()->math(), Math::fenced('(', ')', Math::frac('a', 'b')))->color($red)->apply(\Papercut\Style\Style::make(fontSize: pt(20)));
        $f = $h->layout($eq, 400)->fragment;
        $this->assertNotNull($f);
        $pieces = 0;
        foreach (self::ops($f) as $op) {
            if ($op instanceof DrawSvg) {
                $pieces++;
                $this->assertStringContainsString('#ff0000', $op->svg->xml);
            }
            if ($op instanceof TextRun) {
                $this->assertEquals($red, $op->color);
                $this->assertContains($op->size, [20.0, 14.0, 10.0], 'display text and script sizes of a 20pt equation');
            } elseif ($op instanceof Rect) {
                $this->assertEquals($red, $op->fill);
            }
        }
        $this->assertGreaterThan(0, $pieces, 'the stretched fence is a path');
        $box = Math::layout($eq->math(), 20.0, true, $h->engine->context()->fonts->mathFont()['font'], false);
        $this->assertEqualsWithDelta($box->ascent + $box->descent, $f->height, 0.01);
    }

    public function test_missing_glyph_falls_back(): void
    {
        $backend = new FakeBackend(coverAll: ['Inter-Regular.ttf']);
        $h = new LayoutHarness(fonts: FontRegistry::withDefaults($backend), backend: $backend, fallbackFamilies: ['Inter']);
        $r = $h->layout(Equation::of(Math::text('密度')), 300);
        $this->assertSame(Status::Complete, $r->status);
        $runs = LayoutHarness::fontRuns($r->fragment);
        $this->assertSame(['密', '度'], array_column(array_filter($runs, static fn (array $x): bool => !str_contains($x['font'], 'STIX') && !str_contains($x['font'], 'Math')), 'text'));
        // The fallback face's advance (FakeFont: half the size per character) spaces them, not the math font's .notdef.
        $positions = LayoutHarness::runs($r->fragment);
        $this->assertCount(2, $positions);
        $size = Theme::default()->fontSize;
        $this->assertEqualsWithDelta($positions[0]['x'] + 0.5 * $size, $positions[1]['x'], 0.01);
    }

    public function test_missing_glyph_without_fallback_is_reported(): void
    {
        $h = new LayoutHarness();
        $r = $h->layout(Equation::of(Math::text('密')), 300);
        $this->assertSame(Status::Complete, $r->status);
        $this->assertNotSame([], array_filter($h->warnings, static fn (string $w): bool => str_contains($w, '密')));
    }

    public function test_intrinsic_is_natural_width(): void
    {
        $h = new LayoutHarness();
        $eq = self::quadratic()->padding(pt(3));
        $box = Math::layout($eq->math(), Theme::default()->fontSize, true, $h->engine->context()->fonts->mathFont()['font'], false);
        $this->assertEqualsWithDelta($box->width + 6.0, $h->engine->intrinsic($eq, \Papercut\Style\ComputedStyle::root(Theme::default()))['max'], 1.0);
    }

    public function test_translucent_colour_reaches_path_pieces(): void
    {
        $h = new LayoutHarness();
        $eq = Equation::of(Math::fenced('(', ')', Math::frac('a', 'b')))->color(Color::rgb(255, 0, 0, 0.5));
        $f = $h->layout($eq, 400)->fragment;
        $this->assertNotNull($f);
        $svgs = array_values(array_filter(self::ops($f), static fn (Op $o): bool => $o instanceof DrawSvg));
        $this->assertNotSame([], $svgs);
        $this->assertStringContainsString('fill-opacity="0.5"', $svgs[0]->svg->xml);
    }
}
