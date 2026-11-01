<?php

declare(strict_types=1);

namespace Papercut\Tests\Decorator;

use Papercut\{Document, Page, Text, View};
use Papercut\Decorator\{Decorator, Signature};
use Papercut\Style\{ComputedStyle, StyleResolver, Theme};
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

final class SignatureTest extends TestCase
{
    public function test_is_a_decorator(): void
    {
        self::assertInstanceOf(Decorator::class, Signature::make());
    }

    public function test_blank_line(): void
    {
        $outer = Signature::make()->render();

        self::assertSame('signature', $outer->styleName());
        self::assertCount(1, $outer->children());
        $line = self::node($outer, 0);
        self::assertInstanceOf(View::class, $line);
        self::assertSame('signature.line', $line->styleName());
        self::assertSame([], $line->children());
    }

    public function test_content_on_the_line_and_captions_under_it(): void
    {
        $mark = Text::make('Jane Smith');
        $outer = Signature::make($mark)->name('Jane Smith')->role('Director')->date('12 March 2026')->render();

        $names = array_map(fn ($i) => self::node($outer, $i)->styleName(), array_keys($outer->children()));
        self::assertSame(['signature.line', 'signature.name', 'signature.role', 'signature.date'], $names);
        $line = self::node($outer, 0);
        self::assertInstanceOf(View::class, $line);
        self::assertSame($mark, self::node($line, 0));
        self::assertInstanceOf(Text::class, self::node($outer, 3));
    }

    public function test_blank_captions_are_dropped(): void
    {
        self::assertCount(2, Signature::make()->name('A')->role(' ')->date('')->render()->children());
    }

    public function test_style_and_variants(): void
    {
        $outer = Signature::make()->name('A')->variant('wide')->apply('mine')->render();

        self::assertSame('signature signature.wide? mine', $outer->styleName());
        self::assertSame('signature.line signature.line.wide?', self::node($outer, 0)->styleName());
        self::assertSame(['signature', 'signature.line', 'signature.name', 'signature.role', 'signature.date'], Signature::make()->partNames());
    }

    public function test_default_look(): void
    {
        $theme = Theme::default();
        $resolver = new StyleResolver($theme);
        $outer = Signature::make()->render();
        $block = $resolver->resolve($outer, ComputedStyle::root($theme));
        self::assertTrue($block->keepTogether);
        self::assertEquals(pt(216), $block->width);
        $line = $resolver->resolve(self::node($outer, 0), $block);
        self::assertGreaterThan(0.0, $line->borderWidth->bottom->points);
        self::assertSame(0.0, $line->borderWidth->top->points);
    }

    public function test_lays_out_side_by_side(): void
    {
        $pdf = Document::make(Page::letter()->body(
            \Papercut\Row::make(Signature::make()->name('A'), Signature::make()->name('B'))->gap(pt(24)),
        ))->toPdf()->bytes();
        self::assertStringStartsWith('%PDF', $pdf);
    }

    public function test_beside_a_long_paragraph_it_shrinks_no_further_than_its_captions(): void
    {
        $text = \Papercut\View::make(Text::make(str_repeat('I confirm the details above are correct. ', 10)))->grow(1);
        $row = \Papercut\Row::make($text, Signature::make()->name('Jane Smith')->role('Director'));
        $f = (new \Papercut\Tests\Support\LayoutHarness())->layout($row, 300)->fragment;
        self::assertNotNull($f);
        // FakeFont: 5pt a letter at the base size; the widest word is "Director" at 0.85em, 8 × 4.25 = 34pt
        self::assertEqualsWithDelta(34.0, $f->children()[1]['fragment']->width, 1e-6);
    }

    private static function node(\Papercut\View $parent, int $i): \Papercut\Text|\Papercut\View
    {
        $child = $parent->children()[$i] ?? null;
        self::assertTrue($child instanceof \Papercut\Text || $child instanceof \Papercut\View);
        return $child;
    }
}
