<?php

declare(strict_types=1);

namespace Papercut\Tests\Markup;

use Papercut\Document;
use Papercut\Export\FidelityLevel;
use Papercut\Export\PdfOptions;
use Papercut\Image;
use Papercut\Link;
use Papercut\ListNode;
use Papercut\Markup\Markup;
use Papercut\Markup\MarkupOptions;
use Papercut\Markup\Note;
use Papercut\Markup\StyleMode;
use Papercut\Page;
use Papercut\Span;
use Papercut\Style\ListMarker;
use Papercut\Value\Color;
use Papercut\Tests\Support\TestImages;
use Papercut\Text;
use Papercut\View;
use PHPUnit\Framework\TestCase;

use function Papercut\px;

final class HtmlBlocksTest extends TestCase
{
    public function test_nested_ordered_list_from_sample_105(): void
    {
        $v = Markup::html('<ol><li><i>Select</i> Resources</li><li>Now <i>apply</i> filters:<ol><li>Resource Category</li><li>Resource Providers&nbsp;<ol><li><b>note:</b>&nbsp; free text</li></ol></li></ol></li></ol>');
        self::assertInstanceOf(View::class, $v);
        self::assertCount(1, $v->children());
        $list = $v->children()[0];
        self::assertInstanceOf(ListNode::class, $list);
        self::assertTrue($list->isOrdered());
        self::assertCount(2, $list->items());

        $first = self::only(Text::class, $list->items()[0]->children());
        self::assertNull($first->preset());
        self::assertSame(['Select', ' Resources'], self::spanTexts($first));
        self::assertTrue($first->spanList()[0]->style->italic);

        $second = $list->items()[1]->children();
        self::assertCount(2, $second);
        self::assertInstanceOf(Text::class, $second[0]);
        self::assertSame('Now apply filters:', self::plain($second[0]));
        self::assertInstanceOf(ListNode::class, $second[1]);
        self::assertCount(2, $second[1]->items());

        $providers = $second[1]->items()[1]->children();
        self::assertCount(2, $providers);
        self::assertInstanceOf(Text::class, $providers[0]);
        self::assertSame("Resource Providers\u{00A0}", self::plain($providers[0]));
        self::assertInstanceOf(ListNode::class, $providers[1]);
        self::assertCount(1, $providers[1]->items());

        $note = self::only(Text::class, $providers[1]->items()[0]->children());
        self::assertSame(['note:', "\u{00A0} free text"], self::spanTexts($note));
        self::assertSame(700, $note->spanList()[0]->style->fontWeight);
        self::assertNotSame(700, $note->spanList()[1]->style->fontWeight);
        self::assertSame([], $v->notes());
    }

    public function test_list_attributes(): void
    {
        $v = Markup::html('<ol start="3" type="a"><li>x</li><li value="9">y</li></ol>');
        self::assertInstanceOf(View::class, $v);
        $list = self::only(ListNode::class, $v->children());
        self::assertSame(3, $list->startNumber());
        self::assertSame(ListMarker::LowerAlpha, $list->markerStyle());
        self::assertSame([3, 9], $list->numbers());

        $plain = Markup::html('<ul type="x" start="z"><li>x</li></ul>');
        self::assertInstanceOf(View::class, $plain);
        $ul = self::only(ListNode::class, $plain->children());
        self::assertFalse($ul->isOrdered());
        self::assertSame(1, $ul->startNumber());
        self::assertNull($ul->markerStyle());
    }

    public function test_hostile_list_numbers_clamp_to_int32_and_export_quickly(): void
    {
        $cases = [
            '<ol type="I"><li value="999999999999">a</li></ol>' => [2147483647],
            '<ol type="i" start="100000000"><li>a</li></ol>' => [100000000],
            '<ol start="9223372036854775807"><li>a</li><li>b</li></ol>' => [2147483647, 2147483648],
            '<ol start="99999999999999999999999"><li>a</li></ol>' => [2147483647],
            '<ol type="a" start="-99999999999"><li>a</li><li value="-5">b</li></ol>' => [-2147483648, -5],
            '<ol start=" 03"><li>a</li><li value="7x">b</li></ol>' => [3, 7],
        ];
        foreach ($cases as $html => $numbers) {
            $v = Markup::html($html);
            self::assertInstanceOf(View::class, $v, $html);
            $list = self::only(ListNode::class, $v->children());
            self::assertSame($numbers, $list->numbers(), $html);

            $start = microtime(true);
            $doc = Document::make(Page::letter()->body($v));
            $doc->toPdf(new PdfOptions(strict: true, cacheDir: false));
            $doc->toHtml();
            self::assertLessThan(1.0, microtime(true) - $start, $html);
        }
    }

    public function test_list_item_paragraphs_loose_text_and_stray_li(): void
    {
        $v = Markup::html("<ul>\n  <li><p>one</p><p>two</p></li>\n  stray\n  <li><p>solo</p></li>\n</ul><li>outside</li>");
        self::assertInstanceOf(View::class, $v);
        self::assertCount(2, $v->children());
        $list = $v->children()[0];
        self::assertInstanceOf(ListNode::class, $list);
        self::assertCount(3, $list->items());

        $paras = $list->items()[0]->children();
        self::assertCount(2, $paras);
        foreach ($paras as $p) {
            self::assertInstanceOf(Text::class, $p);
            self::assertSame('p', $p->preset());
        }
        self::assertSame('stray', self::plain(self::only(Text::class, $list->items()[1]->children())));
        $solo = self::only(Text::class, $list->items()[2]->children());
        self::assertNull($solo->preset());
        self::assertSame('solo', self::plain($solo));

        $outside = $v->children()[1];
        self::assertInstanceOf(Text::class, $outside);
        self::assertSame('p', $outside->preset());
        self::assertSame('outside', self::plain($outside));
    }

    public function test_list_directly_inside_a_list_joins_the_previous_item(): void
    {
        $v = Markup::html('<ol><li>a</li><ol><li>b</li></ol><li>c</li></ol>');
        self::assertInstanceOf(View::class, $v);
        $list = self::only(ListNode::class, $v->children());
        self::assertSame([1, 2], $list->numbers());
        $first = $list->items()[0]->children();
        self::assertCount(2, $first);
        self::assertInstanceOf(Text::class, $first[0]);
        self::assertSame('a', self::plain($first[0]));
        self::assertInstanceOf(ListNode::class, $first[1]);
        self::assertSame('b', self::plain(self::only(Text::class, self::only(ListNode::class, [$first[1]])->items()[0]->children())));
        self::assertSame('c', self::plain(self::only(Text::class, $list->items()[1]->children())));

        $lead = Markup::html('<ul><ul><li>x</li></ul><li value="4">y</li>z<ul><li>w</li></ul></ul>');
        self::assertInstanceOf(View::class, $lead);
        $items = self::only(ListNode::class, $lead->children())->items();
        self::assertCount(3, $items);
        self::only(ListNode::class, $items[0]->children());
        self::assertSame('y', self::plain(self::only(Text::class, $items[1]->children())));
        self::assertSame(4, $items[1]->valueOverride());
        $z = $items[2]->children();
        self::assertCount(2, $z);
        self::assertInstanceOf(Text::class, $z[0]);
        self::assertSame('z', self::plain($z[0]));
        self::assertInstanceOf(ListNode::class, $z[1]);
    }

    public function test_resolver_gets_the_raw_src_and_sizes_apply(): void
    {
        $seen = [];
        $o = new MarkupOptions(images: function (string $src) use (&$seen): string {
            $seen[] = $src;
            return TestImages::png();
        });
        $v = Markup::html('<figure><img src="/public/images/medium/x/Screen Shot 2024 &amp; co.png" width="221" height="266"></figure>', $o);
        self::assertSame(['/public/images/medium/x/Screen Shot 2024 & co.png'], $seen);
        self::assertInstanceOf(View::class, $v);
        $figure = self::only(View::class, $v->children());
        $image = self::only(Image::class, $figure->children());
        self::assertSame(['kind' => 'bytes', 'value' => TestImages::png()], $image->source());
        self::assertEquals(px(221), $image->style()->width);
        self::assertNull($image->style()->height);
        self::assertSame([], $v->notes());

        $px = Markup::html('<img src="a" width="40px" height="50%">', $o);
        self::assertInstanceOf(View::class, $px);
        $sized = self::only(Image::class, $px->children());
        self::assertEquals(px(40), $sized->style()->width);
        self::assertNull($sized->style()->height);
    }

    public function test_size_attributes_keep_the_aspect_of_the_bytes_so_tall_images_shrink_to_the_page(): void
    {
        $o = new MarkupOptions(images: fn (string $s): string => TestImages::png(60, 140));
        $v = Markup::html('<figure><img src="a.png" width="600" height="1400"></figure>', $o);
        self::assertInstanceOf(View::class, $v);
        $image = self::only(Image::class, self::only(View::class, $v->children())->children());
        self::assertEquals(px(600), $image->style()->width);
        self::assertNull($image->style()->height);

        $doc = Document::make(Page::letter()->body($v));
        $doc->toPdf(new PdfOptions(strict: true, cacheDir: false));
        $html = $doc->toHtml()->bytes();
        self::assertSame(1, preg_match('/<img [^>]*>/', $html, $tag));
        self::assertStringNotContainsString('height', $tag[0] ?? '');

        $tall = Markup::html('<img src="a.png" height="1400">', $o);
        self::assertInstanceOf(View::class, $tall);
        $image = self::only(Image::class, $tall->children());
        self::assertEquals(px(600), $image->style()->width);
        self::assertNull($image->style()->height);
        Document::make(Page::letter()->body($tall))->toPdf(new PdfOptions(strict: true, cacheDir: false));

        $original = new MarkupOptions(styles: StyleMode::Original, images: fn (string $s): string => TestImages::png(60, 140));
        $css = Markup::html('<img src="a.png" height="1400" style="height: 70px">', $original);
        self::assertInstanceOf(View::class, $css);
        $image = self::only(Image::class, $css->children());
        self::assertEquals(px(600), $image->style()->width);
        self::assertEquals(px(70), $image->style()->height);
    }

    public function test_missing_images(): void
    {
        $throws = new MarkupOptions(images: fn (string $s): ?string => throw new \RuntimeException('disk'));
        $cases = [
            'no resolver' => new MarkupOptions(),
            'null resolver' => new MarkupOptions(images: fn (string $s): ?string => null),
            'throwing resolver' => $throws,
            'not an image' => new MarkupOptions(images: fn (string $s): string => 'not an image'),
        ];
        foreach ($cases as $name => $o) {
            $v = Markup::html('<figure><img src="a.png" alt="image of the search box"></figure>', $o);
            self::assertInstanceOf(View::class, $v, $name);
            $figure = self::only(View::class, $v->children());
            $alt = self::only(Text::class, $figure->children());
            self::assertSame('image of the search box', self::plain($alt), $name);
            self::assertTrue($alt->spanList()[0]->style->italic, $name);
            self::assertSame([[FidelityLevel::Approximated, 'markup <img> "a.png" (missing; shown as its alt text)', 'figure[0] > img[0]']], self::notes($v), $name);

            $none = Markup::html('<figure><img src="b.png"></figure>', $o);
            self::assertInstanceOf(View::class, $none, $name);
            self::assertSame([], $none->children(), $name);
            self::assertSame([[FidelityLevel::Dropped, 'markup <img> "b.png" (missing)', 'figure[0] > img[0]']], self::notes($none), $name);
        }
    }

    public function test_image_without_src_skips_the_resolver_and_notes_shorten_long_srcs(): void
    {
        $calls = 0;
        $o = new MarkupOptions(images: function (string $s) use (&$calls): string {
            $calls++;
            return TestImages::png();
        });
        $v = Markup::html('<img alt="none"><img src="  ">', $o);
        self::assertSame(0, $calls);
        self::assertSame([
            [FidelityLevel::Approximated, 'markup <img> "" (missing; shown as its alt text)', 'img[0]'],
            [FidelityLevel::Dropped, 'markup <img> "  " (missing)', 'img[1]'],
        ], self::notes($v));

        $src = 'data:image/png;base64,' . str_repeat('A', 5000);
        $long = Markup::html('<img src="' . $src . '">');
        self::assertSame([[FidelityLevel::Dropped, 'markup <img> "' . substr($src, 0, 100) . '…" (missing)', 'img[0]']], self::notes($long));
    }

    public function test_linked_image_and_inline_image_split(): void
    {
        $o = new MarkupOptions(images: fn (string $s): string => TestImages::png());
        $v = Markup::html('<figure><a href="https://docs.google.com/x" target="_blank"><img src="a.png" alt="t"></a></figure>', $o);
        self::assertInstanceOf(View::class, $v);
        $figure = self::only(View::class, $v->children());
        $link = self::only(Link::class, $figure->children());
        self::assertSame('https://docs.google.com/x', $link->uri());
        self::only(Image::class, $link->children());
        self::assertSame([], $v->notes());

        $unsafe = Markup::html('<a href="javascript:x"><img src="a.png"></a>', $o);
        self::assertInstanceOf(View::class, $unsafe);
        self::only(Image::class, $unsafe->children());
        self::assertSame([[FidelityLevel::Dropped, 'markup link "javascript:x" (unsupported link; link removed, content kept)', 'a[0]']], self::notes($unsafe));

        $split = Markup::html('<p>before <img src="a.png"> after</p>', $o);
        self::assertInstanceOf(View::class, $split);
        $blocks = $split->children();
        self::assertCount(3, $blocks);
        self::assertInstanceOf(Text::class, $blocks[0]);
        self::assertSame('before', self::plain($blocks[0]));
        self::assertInstanceOf(Image::class, $blocks[1]);
        self::assertInstanceOf(Text::class, $blocks[2]);
        self::assertSame('after', self::plain($blocks[2]));
    }

    public function test_figure_caption_follows_content(): void
    {
        $o = new MarkupOptions(images: fn (string $s): string => TestImages::jpeg());
        $v = Markup::html('<figure><figcaption>Caption</figcaption><img src="a.jpg"></figure>', $o);
        self::assertInstanceOf(View::class, $v);
        $figure = self::only(View::class, $v->children());
        $children = $figure->children();
        self::assertCount(2, $children);
        self::assertInstanceOf(Image::class, $children[0]);
        self::assertInstanceOf(Text::class, $children[1]);
        self::assertSame('p', $children[1]->preset());
        self::assertSame('Caption', self::plain($children[1]));

        $around = Markup::html('<figure>text<figcaption>c</figcaption>more</figure>');
        self::assertInstanceOf(View::class, $around);
        $parts = self::only(View::class, $around->children())->children();
        self::assertSame(['text', 'more', 'c'], array_map(fn ($t) => $t instanceof Text ? self::plain($t) : '?', $parts));

        $text = Markup::html('<figure>loose <b>text</b></figure>');
        self::assertInstanceOf(View::class, $text);
        $figure = self::only(View::class, $text->children());
        $p = self::only(Text::class, $figure->children());
        self::assertSame('p', $p->preset());
        self::assertSame('loose text', self::plain($p));
    }

    public function test_iframe_becomes_a_link(): void
    {
        $t = Markup::html('<figure><iframe src="https://scribehow.com/embed/x?as=scrollable" width="100%" height="640"></iframe></figure>');
        self::assertInstanceOf(View::class, $t);
        $figure = self::only(View::class, $t->children());
        $p = self::only(Text::class, $figure->children());
        $span = self::only(Span::class, $p->spanList());
        self::assertSame('https://scribehow.com/embed/x?as=scrollable', $span->text);
        self::assertSame('https://scribehow.com/embed/x?as=scrollable', $span->href);
        self::assertSame([[FidelityLevel::Approximated, 'markup <iframe> (shown as a link)', 'figure[0] > iframe[0]']], self::notes($t));

        $video = Markup::html('<video src="https://example.com/v.mp4" title="Demo">fallback</video>');
        self::assertInstanceOf(Text::class, $video);
        $span = self::only(Span::class, $video->spanList());
        self::assertSame('Demo', $span->text);
        self::assertSame('https://example.com/v.mp4', $span->href);
        self::assertSame([[FidelityLevel::Approximated, 'markup <video> (shown as a link)', 'video[0]']], self::notes($video));

        $bad = Markup::html('<iframe src="javascript:x"></iframe>');
        self::assertInstanceOf(View::class, $bad);
        self::assertSame([], $bad->children());
        self::assertSame([[FidelityLevel::Dropped, 'markup <iframe> (no usable src; removed)', 'iframe[0]']], self::notes($bad));
    }

    public function test_blockquote_becomes_a_rendered_blockquote_view(): void
    {
        $v = Markup::html('<blockquote><p>Q</p></blockquote>');
        self::assertInstanceOf(View::class, $v);
        $quote = self::only(View::class, $v->children());
        self::assertSame('blockquote', $quote->htmlElement());
        self::assertSame('blockquote', $quote->styleName());
        self::assertSame('Q', self::plain(self::only(Text::class, $quote->children())));
        self::assertSame([], self::notes($v));
    }

    public function test_blockquote_takes_a_last_footer_or_cite_as_its_attribution(): void
    {
        foreach (['footer', 'cite'] as $tag) {
            $v = Markup::html("<blockquote><p>Q</p><$tag>Jane</$tag></blockquote>");
            self::assertInstanceOf(View::class, $v);
            $quote = self::only(View::class, $v->children());
            self::assertCount(2, $quote->children(), $tag);
            $q = $quote->children()[0];
            $cite = $quote->children()[1];
            self::assertInstanceOf(Text::class, $q);
            self::assertInstanceOf(Text::class, $cite);
            self::assertSame('Q', self::plain($q), $tag);
            self::assertSame('blockquote.cite', $cite->styleName(), $tag);
            self::assertSame('— Jane', self::plain($cite), $tag);
            self::assertSame([], self::notes($v), $tag);
        }
    }

    public function test_blockquote_cite_that_is_not_last_stays_inline(): void
    {
        $v = Markup::html('<blockquote><p>Q <cite>Jane</cite> said</p></blockquote>');
        self::assertInstanceOf(View::class, $v);
        $quote = self::only(View::class, $v->children());
        self::assertSame('Q Jane said', self::plain(self::only(Text::class, $quote->children())));
        $v = Markup::html('<blockquote><cite>Jane</cite><p>Q</p></blockquote>');
        self::assertInstanceOf(View::class, $v);
        $quote = self::only(View::class, $v->children());
        self::assertCount(2, $quote->children());
        self::assertSame('blockquote', $quote->styleName());
    }

    public function test_empty_blockquote_is_nothing(): void
    {
        $v = Markup::html('<blockquote></blockquote><p>x</p>');
        self::assertInstanceOf(Text::class, $v);
        self::assertSame('x', self::plain($v));
        $none = Markup::html('<blockquote></blockquote>');
        self::assertInstanceOf(View::class, $none);
        self::assertSame([], $none->children());
    }

    public function test_mark_has_a_yellow_background_and_inline_css_wins(): void
    {
        $t = Markup::html('<p>a <mark>b</mark></p>');
        self::assertInstanceOf(Text::class, $t);
        self::assertEquals(Color::named('yellow-200'), $t->spanList()[1]->style->background);
        self::assertNull($t->spanList()[0]->style->background);
        $o = new MarkupOptions(styles: StyleMode::Original);
        $t = Markup::html('<p>a <mark style="background-color: #ff0000">b</mark></p>', $o);
        self::assertInstanceOf(Text::class, $t);
        self::assertEquals(Color::hex('#ff0000'), $t->spanList()[1]->style->background);
        self::assertSame([], self::notes($t));
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param array<mixed> $children
     * @return T
     */
    private static function only(string $class, array $children): object
    {
        self::assertCount(1, $children);
        $child = array_values($children)[0];
        self::assertInstanceOf($class, $child);
        return $child;
    }

    /** @return list<string> */
    private static function spanTexts(Text $t): array
    {
        return array_map(fn (Span $s) => $s->text, $t->spanList());
    }

    private static function plain(Text $t): string
    {
        return implode('', self::spanTexts($t));
    }

    /** @return list<array{FidelityLevel, string, string}> */
    private static function notes(View|Text $n): array
    {
        return array_map(fn (Note $x) => [$x->level, $x->feature, $x->where], $n->notes());
    }
}
