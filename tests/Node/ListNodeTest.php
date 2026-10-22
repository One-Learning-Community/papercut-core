<?php

declare(strict_types=1);

namespace Papercut\Tests\Node;

use Papercut\{Document, ListItem, ListNode, Node, Page, Text};
use Papercut\Style\{ComputedStyle, ListMarker, StyleResolver, Theme};
use Papercut\Tree\{Region, TreeVisitor, TreeWalker, Visit};
use PHPUnit\Framework\TestCase;

final class ListNodeTest extends TestCase
{
    public function test_labels(): void
    {
        self::assertSame(['1.', 'a.', 'z.', 'aa.', 'iv.', 'mcmxciv.', 'D.', 'XL.'], [
            ListMarker::Decimal->label(1), ListMarker::LowerAlpha->label(1), ListMarker::LowerAlpha->label(26),
            ListMarker::LowerAlpha->label(27), ListMarker::LowerRoman->label(4), ListMarker::LowerRoman->label(1994),
            ListMarker::UpperAlpha->label(4), ListMarker::UpperRoman->label(40),
        ]);
        self::assertSame(['•', '◦', '▪', '', '0.'], [ListMarker::Disc->label(1), ListMarker::Circle->label(1), ListMarker::Square->label(1), ListMarker::None->label(1), ListMarker::LowerAlpha->label(0)]);
    }

    public function test_label_edges(): void
    {
        self::assertSame(['az.', 'zz.', 'AZ.', 'ZZ.'], [
            ListMarker::LowerAlpha->label(52), ListMarker::LowerAlpha->label(702),
            ListMarker::UpperAlpha->label(52), ListMarker::UpperAlpha->label(702),
        ]);
        foreach ([ListMarker::LowerAlpha, ListMarker::UpperAlpha, ListMarker::LowerRoman, ListMarker::UpperRoman] as $m) {
            self::assertSame(['0.', '-1.', PHP_INT_MIN . '.'], [$m->label(0), $m->label(-1), $m->label(PHP_INT_MIN)], $m->value);
        }
        self::assertSame(['mmmcmxcix.', '4000.', 'MMMCMXCIX.', '4000.', PHP_INT_MAX . '.'], [
            ListMarker::LowerRoman->label(3999), ListMarker::LowerRoman->label(4000),
            ListMarker::UpperRoman->label(3999), ListMarker::UpperRoman->label(4000),
            ListMarker::UpperRoman->label(PHP_INT_MAX),
        ]);
        $t = microtime(true);
        self::assertSame('crpxnlskvljfhg.', ListMarker::LowerAlpha->label(PHP_INT_MAX));
        self::assertLessThan(0.01, microtime(true) - $t);
    }

    public function test_numbers_never_overflow_to_float(): void
    {
        self::assertSame([PHP_INT_MAX - 1, PHP_INT_MAX, PHP_INT_MAX], ListNode::ordered('a', 'b', 'c')->start(PHP_INT_MAX - 1)->numbers());
        self::assertSame([PHP_INT_MAX, PHP_INT_MAX, -3, -2], ListNode::ordered(ListItem::make('a')->withValue(PHP_INT_MAX), 'b', ListItem::make('c')->withValue(-3), 'd')->numbers());
        self::assertSame([-5, -4], ListNode::ordered('a', 'b')->start(-5)->numbers());
    }

    public function test_markers_by_depth_cycle(): void
    {
        self::assertSame([ListMarker::Decimal, ListMarker::LowerAlpha, ListMarker::LowerRoman, ListMarker::Decimal], array_map(fn ($d) => ListMarker::forDepth(true, $d), [1, 2, 3, 4]));
        self::assertSame([ListMarker::Disc, ListMarker::Circle, ListMarker::Square, ListMarker::Disc], array_map(fn ($d) => ListMarker::forDepth(false, $d), [1, 2, 3, 4]));
    }

    public function test_html_type_and_ordered(): void
    {
        self::assertSame(ListMarker::UpperRoman, ListMarker::fromHtmlType('I'));
        self::assertNull(ListMarker::fromHtmlType('x'));
        self::assertTrue(ListMarker::LowerAlpha->isOrdered());
        self::assertFalse(ListMarker::Disc->isOrdered());
        self::assertFalse(ListMarker::None->isOrdered());
    }

    public function test_numbers_follow_start_and_value_overrides(): void
    {
        $list = ListNode::ordered('a', 'b', ListItem::make('c')->withValue(10), 'd')->start(3);
        self::assertSame([3, 4, 10, 11], $list->numbers());
        self::assertInstanceOf(Text::class, $list->items()[0]->children()[0]);
        self::assertTrue($list->isOrdered());
        self::assertSame(3, $list->startNumber());
        self::assertNull($list->markerStyle());
        self::assertSame(ListMarker::Square, $list->marker(ListMarker::Square)->markerStyle());
        self::assertSame(1, ListNode::unordered('x')->startNumber());
        self::assertSame('list', $list->debugName());
        self::assertSame('item', $list->items()[0]->debugName());
    }

    public function test_tree_walker_visits_items_and_their_children(): void
    {
        $doc = Document::make(Page::a4()->body(ListNode::unordered('a', ListItem::make('b', ListNode::ordered('c')))));
        $log = [];
        $visitor = new class($log) implements TreeVisitor {
            /** @param list<string> $log */
            public function __construct(public array &$log)
            {
            }

            public function enterPage(Page $page, int $index): void
            {
            }

            public function leavePage(Page $page, int $index): void
            {
            }

            public function enterRegion(Region $region, ComputedStyle $root): void
            {
            }

            public function leaveRegion(Region $region): void
            {
            }

            public function enter(Node $node, ComputedStyle $style, Visit $at): bool
            {
                $this->log[] = $at->path();
                return true;
            }

            public function leave(Node $node, ComputedStyle $style, Visit $at): void
            {
            }
        };
        (new TreeWalker(new StyleResolver(Theme::default())))->walk($doc, $visitor);
        self::assertSame([
            'page > body > list[0]',
            'page > body > list[0] > item[0]',
            'page > body > list[0] > item[0] > text[0]',
            'page > body > list[0] > item[1]',
            'page > body > list[0] > item[1] > text[0]',
            'page > body > list[0] > item[1] > list[1]',
            'page > body > list[0] > item[1] > list[1] > item[0]',
            'page > body > list[0] > item[1] > list[1] > item[0] > text[0]',
        ], $log);
    }
}
