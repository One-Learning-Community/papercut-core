<?php

declare(strict_types=1);

namespace Papercut\Tests\Paint;

use Papercut\Layout\{Fragment, PlacedPage};
use Papercut\Paint\{Anchor, Clip, DrawSvg, Link, Opacity, PageNumberSlot, Painter, Rect, TextRun, TocSlot};
use Papercut\Tests\Support\FakeBackend;
use Papercut\Text\{FontRegistry, SvgFonts};
use Papercut\Vector\SvgDocument;
use Papercut\Style\TextAlign;
use Papercut\Tests\Support\FakeFont;
use Papercut\Text\CachedFont;
use Papercut\Value\Color;
use PHPUnit\Framework\TestCase;

final class PainterTest extends TestCase
{
    public function test_flattens_fragments_and_resolves_page_numbers(): void
    {
        $font = new CachedFont(new FakeFont());

        $child = new Fragment(50, 50);
        $child->addOp(new TextRun(1, 2, 'Hi', $font, 10, Color::black()));

        $faded = new Fragment(20, 20);
        $faded->opacity = 0.5;
        $faded->addOp(new Rect(0, 0, 5, 5, fill: Color::black()));

        $clipped = new Fragment(30, 30);
        $clipped->clip = [0.0, 0.0, 30.0, 30.0];
        $clipped->addOp(new Rect(1, 1, 100, 100, fill: Color::black()));

        $slots = new Fragment(100, 14);
        $slots->addSlot(new PageNumberSlot(0, 10, 100, '{page} of {total}', $font, 10, Color::black(), TextAlign::Right, 0.0));

        $root = new Fragment(200, 200);
        $root->addChild(10, 20, $child);
        $root->addChild(100, 100, $faded);
        $root->addChild(5, 5, $clipped);
        $root->addChild(0, 150, $slots);

        $pages = Painter::paint([new PlacedPage(200, 300, [['x' => 0.0, 'y' => 0.0, 'fragment' => $root]])]);

        self::assertCount(1, $pages);
        self::assertSame([200.0, 300.0], [$pages[0]->width, $pages[0]->height]);
        $ops = $pages[0]->ops;
        self::assertCount(4, $ops);

        self::assertInstanceOf(TextRun::class, $ops[0]);
        self::assertEqualsWithDelta([11.0, 22.0], [$ops[0]->x, $ops[0]->baseline], 1e-9);

        self::assertInstanceOf(Opacity::class, $ops[1]);
        self::assertSame(0.5, $ops[1]->alpha);
        self::assertCount(1, $ops[1]->ops);
        self::assertInstanceOf(Rect::class, $ops[1]->ops[0]);
        self::assertEqualsWithDelta([100.0, 100.0], [$ops[1]->ops[0]->x, $ops[1]->ops[0]->y], 1e-9);

        self::assertInstanceOf(Clip::class, $ops[2]);
        self::assertEqualsWithDelta([5.0, 5.0, 30.0, 30.0], [$ops[2]->x, $ops[2]->y, $ops[2]->width, $ops[2]->height], 1e-9);
        self::assertInstanceOf(Rect::class, $ops[2]->ops[0]);
        self::assertEqualsWithDelta([6.0, 6.0], [$ops[2]->ops[0]->x, $ops[2]->ops[0]->y], 1e-9);

        self::assertInstanceOf(TextRun::class, $ops[3]);
        self::assertSame('1 of 1', $ops[3]->text);
        self::assertEqualsWithDelta([70.0, 160.0], [$ops[3]->x, $ops[3]->baseline], 1e-9);
    }

    public function test_svg_drawings_move_with_their_fragment(): void
    {
        $svg = SvgDocument::parse('<svg width="10" height="10"/>');
        $fonts = new SvgFonts(FontRegistry::withDefaults(new FakeBackend()), 'Inter');
        $f = new Fragment(50, 50);
        $f->addOp(new Clip(1, 2, 30, 30, [new DrawSvg(-4, 2, 40, 20, $svg, $fonts)]));
        $root = new Fragment(200, 200);
        $root->addChild(10, 20, $f);

        $ops = Painter::paint([new PlacedPage(200, 300, [['x' => 0.0, 'y' => 0.0, 'fragment' => $root]])])[0]->ops;

        self::assertInstanceOf(Clip::class, $ops[0]);
        $draw = $ops[0]->ops[0];
        self::assertInstanceOf(DrawSvg::class, $draw);
        self::assertEqualsWithDelta([6.0, 22.0, 40.0, 20.0], [$draw->x, $draw->y, $draw->width, $draw->height], 1e-9);
        self::assertSame($svg, $draw->svg);
        self::assertSame($fonts, $draw->fonts);
    }

    public function test_page_number_counts_pages_and_aligns(): void
    {
        $font = new CachedFont(new FakeFont());
        $f = new Fragment(100, 14);
        $f->addSlot(new PageNumberSlot(0, 10, 100, '{page}/{total}', $font, 10, Color::black(), TextAlign::Center, 0.0));
        $page = new PlacedPage(100, 100, [['x' => 0.0, 'y' => 0.0, 'fragment' => $f]]);

        $pages = Painter::paint([$page, $page, $page]);

        self::assertCount(3, $pages);
        $run = $pages[1]->ops[0];
        self::assertInstanceOf(TextRun::class, $run);
        self::assertSame('2/3', $run->text);
        self::assertEqualsWithDelta(42.5, $run->x, 1e-9); // (100 - 15) / 2
    }

    private static function tocSlot(string $dest, bool $leaders = true, float $x = 150.0, float $boxWidth = 200.0): TocSlot
    {
        $font = new CachedFont(new FakeFont());
        return new TocSlot($dest, $x, 10.0, $boxWidth - $x, 40.0, $leaders, $font, 10.0, Color::black(), 0.0, $boxWidth, 14.0);
    }

    private static function anchorFragment(string $name): Fragment
    {
        $f = new Fragment(10, 10);
        $f->addOp(new Anchor($name, 0.0, 0.0));
        return $f;
    }

    /**
     * @param list<Fragment> $fragments one per page
     * @return list<PlacedPage>
     */
    private static function pagesOf(array $fragments): array
    {
        return array_map(static function (Fragment $f): PlacedPage {
            $root = new Fragment(200, 300);
            $root->addChild(0, 20, $f);
            return new PlacedPage(200, 300, [['x' => 0.0, 'y' => 0.0, 'fragment' => $root]]);
        }, $fragments);
    }

    public function test_toc_slots_get_numbers_leaders_and_links(): void
    {
        $toc = new Fragment(200, 14);
        $toc->addSlot(self::tocSlot('a'));
        $toc->addSlot(self::tocSlot('b'));
        $pages = self::pagesOf([$toc, self::anchorFragment('a'), self::anchorFragment('b')]);

        $lists = Painter::paint($pages);

        $runs = array_values(array_filter($lists[0]->ops, static fn ($o): bool => $o instanceof TextRun));
        self::assertCount(4, $runs);
        [$numA, $dotsA, $numB, $dotsB] = $runs;
        self::assertSame(['2', '3'], [$numA->text, $numB->text]);
        foreach ([$numA, $numB] as $n) {
            self::assertEqualsWithDelta(150.0 + 50.0, $n->x + $n->font->advance($n->text, $n->size, $n->letterSpacing), 0.01);
            self::assertEqualsWithDelta(30.0, $n->baseline, 0.01);
        }
        foreach ([[$numA, $dotsA], [$numB, $dotsB]] as [$n, $d]) {
            self::assertMatchesRegularExpression('/^\.+$/', $d->text);
            self::assertLessThan($n->x, $d->x + $d->font->advance($d->text, $d->size, $d->letterSpacing));
            self::assertGreaterThanOrEqual(40.0 + 3.0, $d->x);
        }
        $links = array_values(array_filter($lists[0]->ops, static fn ($o): bool => $o instanceof Link));
        self::assertSame(['#a', '#b'], array_map(static fn (Link $l): string => $l->uri, $links));
        self::assertEqualsWithDelta([0.0, 20.0, 200.0, 14.0], [$links[0]->x, $links[0]->y, $links[0]->width, $links[0]->height], 0.01);
    }

    public function test_toc_slot_without_leaders_or_room_has_no_dot_run(): void
    {
        $toc = new Fragment(200, 14);
        $toc->addSlot(self::tocSlot('a', leaders: false));
        $toc->addSlot(self::tocSlot('a', x: 46.0, boxWidth: 50.0));
        $lists = Painter::paint(self::pagesOf([$toc, self::anchorFragment('a')]));

        $runs = array_values(array_filter($lists[0]->ops, static fn ($o): bool => $o instanceof TextRun));
        self::assertSame(['2', '2'], array_map(static fn (TextRun $r): string => $r->text, $runs));
    }

    public function test_anchor_pages_keeps_the_first_occurrence(): void
    {
        $pages = self::pagesOf([self::anchorFragment('x'), self::anchorFragment('a'), self::anchorFragment('a'), self::anchorFragment('a')]);

        self::assertSame(['x' => 1, 'a' => 2], Painter::anchorPages($pages));
    }

    public function test_toc_slot_with_no_anchor_produces_nothing(): void
    {
        $toc = new Fragment(200, 14);
        $toc->addSlot(self::tocSlot('missing'));
        $lists = Painter::paint(self::pagesOf([$toc]));

        self::assertSame([], $lists[0]->ops);
    }
}
