<?php

declare(strict_types=1);

namespace Papercut\Tests\Paint;

use Papercut\Layout\{Fragment, PlacedPage};
use Papercut\Paint\{Clip, Opacity, PageNumberSlot, Painter, Rect, TextRun};
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
}
