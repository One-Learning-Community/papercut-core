<?php

declare(strict_types=1);

namespace Papercut\Tests\Html;

use Papercut\{Document, ListItem, ListNode, Page, Text};
use Papercut\Style\ListMarker;
use PHPUnit\Framework\TestCase;

final class HtmlListsTest extends TestCase
{
    private function html(Document $doc): string
    {
        return $doc->toHtml()->bytes();
    }

    /** @return list<\Dom\Element> */
    private function xpath(string $html, string $query): array
    {
        $dom = \Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><head><title>t</title></head><body>' . $html . '</body></html>');
        $xp = new \Dom\XPath($dom);
        $xp->registerNamespace('h', 'http://www.w3.org/1999/xhtml');
        $query = (string) preg_replace('~(?<=/)([a-z][a-z0-9]*)(?![a-z0-9(\-@])~', 'h:$1', $query);
        $found = [];
        foreach ($xp->query($query) as $n) {
            self::assertInstanceOf(\Dom\Element::class, $n);
            $found[] = $n;
        }
        return $found;
    }

    private function style(\Dom\Element $e): string
    {
        return (string) $e->getAttribute('style');
    }

    private function body(\Papercut\Node|\Papercut\Component ...$nodes): Document
    {
        return Document::make(Page::a4()->body(...$nodes));
    }

    public function test_nested_lists_carry_their_depth_marker(): void
    {
        $html = $this->html($this->body(ListNode::ordered('a', ListItem::make('b', ListNode::ordered('c', ListItem::make('d', ListNode::unordered('e')))))));
        self::assertStringContainsString('list-style-type:decimal', $this->style($this->xpath($html, '//ol')[0]));
        self::assertStringContainsString('list-style-type:lower-alpha', $this->style($this->xpath($html, '//ol/li/ol')[0]));
        self::assertStringContainsString('list-style-type:disc', $this->style($this->xpath($html, '//ul')[0]));
        self::assertSame('c', trim((string) $this->xpath($html, '//ol/li/ol/li')[0]->textContent));
    }

    public function test_start_value_and_explicit_marker(): void
    {
        $html = $this->html($this->body(ListNode::ordered('a', ListItem::make('b')->withValue(7))->start(3)->marker(ListMarker::UpperRoman)));
        self::assertCount(1, $this->xpath($html, '//ol[@start="3"]'));
        self::assertCount(1, $this->xpath($html, '//ol/li[2][@value="7"]'));
        self::assertStringContainsString('list-style-type:upper-roman', $this->style($this->xpath($html, '//ol')[0]));
    }

    public function test_default_start_is_omitted_and_unordered_never_has_one(): void
    {
        $html = $this->html($this->body(ListNode::ordered('a'), ListNode::unordered('b')->start(5)));
        self::assertStringNotContainsString(' start=', $html);
        self::assertStringNotContainsString(' value=', $html);
    }

    public function test_list_depth_counts_per_kind(): void
    {
        $html = $this->html($this->body(ListNode::unordered(ListItem::make('a', ListNode::ordered('b', ListItem::make('c', ListNode::unordered('d')))))));
        self::assertStringContainsString('list-style-type:decimal', $this->style($this->xpath($html, '//ul/li/ol')[0]));
        self::assertStringContainsString('list-style-type:circle', $this->style($this->xpath($html, '//ul/li/ol/li/ul')[0]));
    }

    public function test_item_children_are_written_inside_the_li(): void
    {
        $html = $this->html($this->body(ListNode::unordered(ListItem::make(Text::make('x'), Text::make('y')))));
        self::assertCount(2, $this->xpath($html, '//ul/li/p'));
    }

    public function test_explicit_marker_lists_still_count_toward_depth(): void
    {
        $html = $this->html($this->body(
            ListNode::ordered(ListItem::make('a', ListNode::ordered('b')))->marker(ListMarker::UpperRoman),
            ListNode::ordered('c'),
        ));
        self::assertStringContainsString('list-style-type:lower-alpha', $this->style($this->xpath($html, '//ol/li/ol')[0]));
        self::assertStringContainsString('list-style-type:decimal', $this->style($this->xpath($html, '//ol')[2]));
    }
}
