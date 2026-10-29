<?php

declare(strict_types=1);

namespace Papercut\Tests\Node;

use Papercut\{Page, PageVariant};
use PHPUnit\Framework\TestCase;

use function Papercut\mm;

final class PageVariantsTest extends TestCase
{
    public function test_variants_are_unset_by_default(): void
    {
        $page = Page::a4();
        self::assertNull($page->firstHeaderNodes());
        self::assertNull($page->firstFooterNodes());
        self::assertNull($page->evenHeaderNodes());
        self::assertNull($page->evenFooterNodes());
        self::assertFalse($page->mirrorsMargins());
    }

    public function test_calling_a_variant_with_no_arguments_sets_it_empty(): void
    {
        self::assertSame([], Page::a4()->firstFooter()->firstFooterNodes());
    }

    public function test_variants_append_and_an_empty_call_keeps_content(): void
    {
        $base = Page::a4();
        $one = $base->firstHeader('a');
        $two = $one->firstHeader('b');
        self::assertCount(2, $two->firstHeaderNodes() ?? []);
        self::assertCount(2, $two->firstHeader()->firstHeaderNodes() ?? []);
        self::assertCount(1, $one->firstHeaderNodes() ?? []);
        self::assertNull($base->firstHeaderNodes());
    }

    public function test_variant_selection_first_wins_then_even(): void
    {
        $page = Page::a4()->firstHeader('f')->evenHeader('e');
        self::assertSame(PageVariant::First, $page->headerVariantOn(true, 1));
        self::assertSame(PageVariant::First, $page->headerVariantOn(true, 4));
        self::assertSame(PageVariant::Even, $page->headerVariantOn(false, 4));
        self::assertSame(PageVariant::Default, $page->headerVariantOn(false, 3));

        $footer = Page::a4()->evenFooter('e');
        self::assertSame(PageVariant::Even, $footer->footerVariantOn(true, 4));
        self::assertSame(PageVariant::Default, $footer->footerVariantOn(true, 1));
    }

    public function test_nodes_of_a_variant(): void
    {
        $page = Page::a4()->header('h')->footer('f')->firstHeader()->evenFooter('e');
        self::assertSame($page->headerNodes(), $page->headerNodesOf(PageVariant::Default));
        self::assertSame([], $page->headerNodesOf(PageVariant::First));
        self::assertCount(1, $page->footerNodesOf(PageVariant::Even));
        self::assertSame($page->footerNodes(), $page->footerNodesOf(PageVariant::Default));
    }

    public function test_an_unset_variant_has_no_nodes_of_its_own(): void
    {
        $page = Page::a4()->header('h')->footer('f');
        self::assertSame([], $page->headerNodesOf(PageVariant::Even));
        self::assertSame([], $page->footerNodesOf(PageVariant::Even));
        self::assertSame([], $page->headerNodesOf(PageVariant::First));
    }

    public function test_mirrored_margins_swap_left_and_right_on_even_pages(): void
    {
        $page = Page::a4()->margin(mm(10), mm(15), mm(20), mm(25))->mirrorMargins();
        self::assertTrue($page->mirrorsMargins());
        $odd = $page->marginsOn(1);
        self::assertEquals(mm(25), $odd->left);
        self::assertEquals(mm(15), $odd->right);
        $even = $page->marginsOn(2);
        self::assertEquals(mm(15), $even->left);
        self::assertEquals(mm(25), $even->right);
        self::assertEquals(mm(10), $even->top);
        self::assertEquals(mm(20), $even->bottom);

        $plain = Page::a4()->margin(mm(10), mm(15), mm(20), mm(25));
        self::assertEquals($plain->margins(), $plain->marginsOn(2));
        self::assertFalse($page->mirrorMargins(false)->mirrorsMargins());
    }
}
