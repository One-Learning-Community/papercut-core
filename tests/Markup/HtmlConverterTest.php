<?php

declare(strict_types=1);

namespace Papercut\Tests\Markup;

use Papercut\Export\FidelityLevel;
use Papercut\Image;
use Papercut\ListNode;
use Papercut\Markup\BlankParagraphs;
use Papercut\Markup\Markup;
use Papercut\Markup\MarkupOptions;
use Papercut\Markup\Note;
use Papercut\Markup\StyleMode;
use Papercut\Span;
use Papercut\Style\ListMarker;
use Papercut\Style\TextAlign;
use Papercut\Tests\Support\TestImages;
use Papercut\Text;
use Papercut\Value\Length;
use Papercut\View;
use PHPUnit\Framework\TestCase;

use function Papercut\px;

final class HtmlConverterTest extends TestCase
{
    public function test_single_paragraph_unwraps_and_inherits(): void
    {
        $t = Markup::html('<p>Strongly agree</p>');
        self::assertInstanceOf(Text::class, $t);
        self::assertNull($t->preset());
        self::assertSame('Strongly agree', $t->spanList()[0]->text);
    }

    public function test_null_and_empty_produce_an_empty_view(): void
    {
        foreach ([null, '', '   ', '<p></p>'] as $html) {
            $v = Markup::html($html);
            self::assertInstanceOf(View::class, $v);
            self::assertSame([], $v->children());
            self::assertSame([], $v->notes());
        }
    }

    public function test_bold_italic_and_br(): void
    {
        $v = Markup::html('<p><b>Welcome to our brand new survey tool!</b><br></p><p>We are <i>excited</i>.</p>');
        self::assertInstanceOf(View::class, $v);
        $children = self::texts($v);
        self::assertCount(2, $children);
        self::assertSame(['p', 'p'], array_map(fn (Text $t) => $t->preset(), $children));

        $first = $children[0]->spanList();
        self::assertCount(1, $first);
        self::assertSame('Welcome to our brand new survey tool!', $first[0]->text);
        self::assertSame(700, $first[0]->style->fontWeight);

        $second = $children[1]->spanList();
        self::assertSame(['We are ', 'excited', '.'], self::spanTexts($second));
        self::assertNotTrue($second[0]->style->italic);
        self::assertTrue($second[1]->style->italic);
        self::assertNotTrue($second[2]->style->italic);
    }

    public function test_inner_br_breaks_the_line_and_other_inline_styles(): void
    {
        $t = Markup::html('<p>a <br> <u>b</u><s>c</s><strike>d</strike><del>e</del><strong>f</strong><em>g</em><span>h</span><a>i</a></p>');
        self::assertInstanceOf(Text::class, $t);
        $spans = $t->spanList();
        self::assertSame(['a', '', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i'], self::spanTexts($spans));
        self::assertTrue($spans[1]->lineBreak);
        self::assertTrue($spans[2]->style->underline);
        self::assertTrue($spans[3]->style->strike);
        self::assertTrue($spans[4]->style->strike);
        self::assertTrue($spans[5]->style->strike);
        self::assertSame(700, $spans[6]->style->fontWeight);
        self::assertTrue($spans[7]->style->italic);
        self::assertNull($spans[9]->href);
        self::assertSame([], $t->notes());
    }

    public function test_whitespace_nbsp_and_zero_width(): void
    {
        $t = Markup::html("<p>\n    Refer to the   Tiles Block Guide\n</p>");
        self::assertInstanceOf(Text::class, $t);
        self::assertSame('Refer to the Tiles Block Guide', $t->spanList()[0]->text);
        $t2 = Markup::html("<p>a&nbsp; &nbsp;b\u{FEFF}</p>");
        self::assertInstanceOf(Text::class, $t2);
        self::assertSame("a\u{00A0} \u{00A0}b", $t2->spanList()[0]->text);
    }

    public function test_whitespace_collapses_across_inline_boundaries(): void
    {
        $t = Markup::html("<p> a <b> b\u{200B} </b> c </p>");
        self::assertInstanceOf(Text::class, $t);
        self::assertSame(['a ', 'b ', 'c'], self::spanTexts($t->spanList()));
    }

    public function test_blank_paragraphs_drop_by_default_and_keep_on_request(): void
    {
        $html = '<p>a</p><p></p><p><i>&nbsp;</i></p><p><i></i></p><p>b</p><p><br></p>';
        $dropped = Markup::html($html);
        self::assertInstanceOf(View::class, $dropped);
        self::assertCount(2, $dropped->children());
        $kept = Markup::html($html, new MarkupOptions(blankParagraphs: BlankParagraphs::Keep));
        self::assertInstanceOf(View::class, $kept);
        self::assertCount(3, $kept->children()); // a, the &nbsp; line, b — the trailing <br> line is trimmed as trailing blank space
        $blank = self::texts($kept)[1];
        self::assertSame(["\u{00A0}"], self::spanTexts($blank->spanList()));
        self::assertSame('p', $blank->preset());
    }

    public function test_leading_blank_lines_are_trimmed_in_keep_mode(): void
    {
        $t = Markup::html('<p><br></p><p>&nbsp;</p><p>x</p>', new MarkupOptions(blankParagraphs: BlankParagraphs::Keep));
        self::assertInstanceOf(Text::class, $t);
        self::assertSame(['x'], self::spanTexts($t->spanList()));
    }

    public function test_links(): void
    {
        $t = Markup::html('<p><a href="https://example.com" target="_blank">x</a> <a href="javascript:alert(1)">y</a></p>');
        self::assertInstanceOf(Text::class, $t);
        self::assertSame('https://example.com', $t->spanList()[0]->href);
        self::assertNull($t->spanList()[2]->href);
        self::assertSame([[FidelityLevel::Dropped, 'markup link "javascript:alert(1)" (unsupported link; link removed, content kept)', 'p[0] > a[1]']], self::notes($t));
    }

    public function test_unknown_and_removed_tags(): void
    {
        $v = Markup::html('<p>a<mark>b</mark></p><script>alert(1)</script><p>c</p>');
        self::assertInstanceOf(View::class, $v);
        self::assertSame(['ab', 'c'], array_map(fn (Text $t) => implode('', self::spanTexts($t->spanList())), self::texts($v)));
        self::assertSame([
            [FidelityLevel::Approximated, 'markup <script> (removed)', 'script[1]'],
        ], self::notes($v));
    }

    public function test_unknown_block_tags_end_the_paragraph_and_keep_their_content(): void
    {
        $cases = [
            '<section>One</section><section>Two</section>' => [['One', 'Two'], [[FidelityLevel::Approximated, 'markup <section> (tag removed, content kept)', 'section[0]']]],
            '<dl><dt>Term</dt><dd>Def</dd></dl>' => [['Term', 'Def'], [
                [FidelityLevel::Approximated, 'markup <dl> (tag removed, content kept)', 'dl[0]'],
                [FidelityLevel::Approximated, 'markup <dt> (tag removed, content kept)', 'dl[0] > dt[0]'],
                [FidelityLevel::Approximated, 'markup <dd> (tag removed, content kept)', 'dl[0] > dd[1]'],
            ]],
        ];
        foreach ($cases as $html => [$texts, $notes]) {
            $v = Markup::html($html);
            self::assertInstanceOf(View::class, $v, $html);
            self::assertSame($texts, array_map(fn (Text $t) => implode('', self::spanTexts($t->spanList())), self::texts($v)), $html);
            self::assertSame(array_fill(0, count($texts), 'p'), array_map(fn (Text $t) => $t->preset(), self::texts($v)), $html);
            self::assertSame($notes, self::notes($v), $html);
        }
    }

    public function test_markup_pre_keeps_spaces_and_lines(): void
    {
        $t = Markup::html("<pre>a  b\n  c</pre>");
        self::assertInstanceOf(Text::class, $t);
        self::assertSame('pre', $t->styleName());
        self::assertSame(['a  b', '', '  c'], self::spanTexts($t->spanList()));
        self::assertSame([false, true, false], array_map(fn (Span $s) => $s->lineBreak, $t->spanList()));
    }

    public function test_pre_keeps_inline_markup_blank_lines_and_tabs(): void
    {
        $t = Markup::html("<pre>line  1\n<b>line</b> 2\n\n\tline 4\n</pre>");
        self::assertInstanceOf(Text::class, $t);
        self::assertSame(['line  1', '', 'line', ' 2', '', '', "\tline 4"], self::spanTexts($t->spanList()));
        self::assertSame([false, true, false, false, true, true, false], array_map(fn (Span $s) => $s->lineBreak, $t->spanList()));
    }

    public function test_markup_pre_code_is_one_block(): void
    {
        $v = Markup::html("<p>Before</p><pre><code>x = 1;\n  y = 2;</code></pre><p>After</p>");
        self::assertInstanceOf(View::class, $v);
        $texts = self::texts($v);
        self::assertCount(3, $texts);
        self::assertSame('pre', $texts[1]->styleName());
        self::assertSame(['x = 1;', '', '  y = 2;'], self::spanTexts($texts[1]->spanList()));
        foreach ($texts[1]->spanList() as $span) {
            self::assertNull($span->styleName, 'the inner code style is not applied inside pre');
        }
    }

    public function test_a_pre_inside_code_gets_no_code_style(): void
    {
        $v = Markup::html("<p>Before</p><code><pre>x = 1;\n  y = 2;</pre></code>");
        self::assertInstanceOf(View::class, $v);
        $texts = self::texts($v);
        self::assertSame('pre', $texts[1]->styleName());
        foreach ($texts[1]->spanList() as $span) {
            self::assertNull($span->styleName, 'the code span style is not applied inside pre');
        }
    }

    public function test_markup_inline_code_kbd_samp_tt_use_code_style(): void
    {
        foreach (['code', 'kbd', 'samp', 'tt'] as $tag) {
            $t = Markup::html("<p>Press <$tag>Ctrl</$tag> now</p>");
            self::assertInstanceOf(Text::class, $t, $tag);
            $span = $t->spanList()[1];
            self::assertSame('Ctrl', $span->text, $tag);
            self::assertSame('code', $span->styleName, $tag);
            self::assertNull($t->spanList()[0]->styleName, $tag);
            self::assertNull($t->spanList()[2]->styleName, $tag);
            self::assertSame([], self::notes($t), $tag);
        }
    }

    public function test_code_style_reaches_nested_inline_markup(): void
    {
        $t = Markup::html('<p>a <code>x <b>y</b></code> z</p>');
        self::assertInstanceOf(Text::class, $t);
        self::assertSame([null, 'code', 'code', null], array_map(fn (Span $s) => $s->styleName, $t->spanList()));
        self::assertSame(700, $t->spanList()[2]->style->fontWeight);
    }

    public function test_a_pre_after_loose_list_text_joins_the_item(): void
    {
        $v = Markup::html('<ul><li>a</li><pre>b</pre></ul>');
        self::assertInstanceOf(View::class, $v);
        $l = $v->children()[0];
        self::assertInstanceOf(ListNode::class, $l);
        self::assertCount(1, $l->items());
        self::assertCount(2, $l->items()[0]->children());
    }

    public function test_flattened_pre_keeps_its_style_name_on_the_spans(): void
    {
        $t = Markup::flatten(Markup::html("<pre>a  b\n c</pre>"));
        $named = array_filter($t->spanList(), fn (Span $s) => !$s->lineBreak);
        self::assertSame(['pre', 'pre'], array_values(array_map(fn (Span $s) => $s->styleName, $named)));
        $v = Markup::flatten(Markup::html("<p>x</p><pre>a</pre>"));
        self::assertSame([null, 'pre'], array_values(array_map(fn (Span $s) => $s->styleName, array_filter($v->spanList(), fn (Span $s) => !$s->lineBreak))));
    }

    public function test_markup_pre_has_no_approximation_note(): void
    {
        $t = Markup::html("<pre>a\n b</pre>");
        self::assertSame([], self::notes($t));
    }

    public function test_unknown_tag_notes_once_per_tag_name(): void
    {
        $v = Markup::html('<p><abbr>a</abbr> <abbr>b</abbr></p><p><abbr>c</abbr><cite>d</cite></p>');
        self::assertSame([
            [FidelityLevel::Approximated, 'markup <abbr> (tag removed, content kept)', 'p[0] > abbr[0]'],
            [FidelityLevel::Approximated, 'markup <cite> (tag removed, content kept)', 'p[1] > cite[1]'],
        ], self::notes($v));
    }

    public function test_every_removed_tag_drops_its_content(): void
    {
        foreach (['script', 'style', 'template', 'noscript', 'object', 'embed'] as $tag) {
            $html = $tag === 'embed' ? '<p>x</p><embed src="y">' : "<p>x</p><$tag>y</$tag>";
            $t = Markup::html($html);
            self::assertInstanceOf(Text::class, $t, $tag);
            self::assertSame(['x'], self::spanTexts($t->spanList()), $tag);
            self::assertSame([[FidelityLevel::Approximated, "markup <$tag> (removed)", "{$tag}[1]"]], self::notes($t), $tag);
        }
    }

    public function test_inline_content_between_blocks_forms_anonymous_paragraphs(): void
    {
        $v = Markup::html('loose <b>text</b><p>para</p>tail');
        self::assertInstanceOf(View::class, $v);
        $texts = self::texts($v);
        self::assertSame([['loose ', 'text'], ['para'], ['tail']], array_map(fn (Text $t) => self::spanTexts($t->spanList()), $texts));
        self::assertSame(['p', 'p', 'p'], array_map(fn (Text $t) => $t->preset(), $texts));
    }

    public function test_div_is_a_paragraph_and_nested_blocks_split_it(): void
    {
        $v = Markup::html('<div>a<p>b</p>c</div>');
        self::assertInstanceOf(View::class, $v);
        self::assertSame([['a'], ['b'], ['c']], array_map(fn (Text $t) => self::spanTexts($t->spanList()), self::texts($v)));
    }

    public function test_headings(): void
    {
        $v = Markup::html('<h1>A</h1><h6>B</h6>');
        self::assertInstanceOf(View::class, $v);
        self::assertSame(['h1', 'h4'], array_map(fn (Text $t) => $t->preset(), self::texts($v)));
        $single = Markup::html('<h2>Only</h2>');
        self::assertInstanceOf(View::class, $single);
        self::assertSame(['h2'], array_map(fn (Text $t) => $t->preset(), self::texts($single)));
    }

    public function test_malformed_html_never_throws_or_warns(): void
    {
        set_error_handler(fn () => self::fail('PHP warning leaked'));
        try {
            foreach (['<p><b>unclosed', '</p>stray', '<li>orphan</li>', "<p>\xC3\x28</p>", '<<>>', str_repeat('<div>', 500), 'a</div>b'] as $html) {
                Markup::html($html);
            }
        } finally {
            restore_error_handler();
        }
        $this->addToAssertionCount(1);
    }

    public function test_stray_closing_tag_does_not_escape_the_container(): void
    {
        $t = Markup::html('a</div>b');
        self::assertInstanceOf(Text::class, $t);
        self::assertSame('ab', implode('', self::spanTexts($t->spanList())));
    }

    public function test_deep_nesting_is_flattened_with_bounded_memory(): void
    {
        foreach (['div', 'b'] as $tag) {
            $html = str_repeat("<$tag>", 10_000) . 'deep' . str_repeat("</$tag>", 10_000) . '<p>after</p>';
            gc_collect_cycles();
            memory_reset_peak_usage();
            $start = memory_get_usage();
            set_error_handler(fn () => self::fail('PHP warning leaked'));
            try {
                $v = Markup::html($html);
            } finally {
                restore_error_handler();
            }
            self::assertLessThan(32 * 1024 * 1024, memory_get_peak_usage() - $start, $tag);

            self::assertInstanceOf(View::class, $v, $tag);
            self::assertSame(['deep', 'after'], array_map(fn (Text $t) => implode('', self::spanTexts($t->spanList())), self::texts($v)), $tag);
            $depth = array_values(array_filter($v->notes(), fn (Note $n) => $n->feature === 'markup nesting deeper than 256 levels (flattened)'));
            self::assertCount(1, $depth, $tag);
            self::assertSame(FidelityLevel::Approximated, $depth[0]->level);
            $three = implode(' > ', array_fill(0, 3, "{$tag}[0]"));
            self::assertSame($three . ' > … > ' . $three, $depth[0]->where, $tag);
            self::assertCount(1, $v->notes(), $tag);
        }
    }

    public function test_link_href_is_trimmed(): void
    {
        $t = Markup::html("<p><a href=\" https://e.com\n\">x</a><a href=\" javascript:x \">y</a></p>");
        self::assertInstanceOf(Text::class, $t);
        self::assertSame('https://e.com', $t->spanList()[0]->href);
        self::assertSame([[FidelityLevel::Dropped, 'markup link "javascript:x" (unsupported link; link removed, content kept)', 'p[0] > a[1]']], self::notes($t));
    }

    public function test_unsupported_link_notes_shorten_the_href(): void
    {
        $href = 'data:text/html;base64,' . str_repeat('A', 5000);
        $t = Markup::html('<p><a href="' . $href . '">x</a> <a href="/relative/page">y</a></p>');
        self::assertSame([
            [FidelityLevel::Dropped, 'markup link "' . substr($href, 0, 100) . '…" (unsupported link; link removed, content kept)', 'p[0] > a[0]'],
            [FidelityLevel::Dropped, 'markup link "/relative/page" (unsupported link; link removed, content kept)', 'p[0] > a[1]'],
        ], self::notes($t));
    }

    public function test_resolver_called_once_per_trimmed_src(): void
    {
        $calls = [];
        $images = function (string $s) use (&$calls): ?string {
            $calls[] = $s;
            return $s === 'gone.png' ? null : TestImages::png();
        };
        $html = str_repeat('<img src="a.png">', 50) . '<img src=" a.png ">' . str_repeat('<img src="gone.png" alt="x">', 5);
        Markup::html($html, new MarkupOptions(images: $images));
        self::assertSame(['a.png', 'gone.png'], array_map('trim', $calls));
        self::assertCount(2, $calls);
    }

    public function test_theme_ignores_style_and_original_applies_it(): void
    {
        $html = '<figure style="max-width: 221px;"><img src="a.png" width="221" height="266" style="width: 100px"></figure>';
        $images = fn (string $s): string => TestImages::png();

        $theme = Markup::html($html, new MarkupOptions(images: $images));
        self::assertInstanceOf(View::class, $theme);
        $figure = $theme->children()[0];
        self::assertInstanceOf(View::class, $figure);
        self::assertNull($figure->style()->maxWidth);
        $image = $figure->children()[0];
        self::assertInstanceOf(Image::class, $image);
        self::assertEqualsWithDelta(px(221)->points, self::points($image->style()->width), 1e-9);
        self::assertSame([], $theme->notes());

        $original = Markup::html($html, new MarkupOptions(styles: StyleMode::Original, images: $images));
        self::assertInstanceOf(View::class, $original);
        $figure = $original->children()[0];
        self::assertInstanceOf(View::class, $figure);
        self::assertEqualsWithDelta(px(221)->points, self::points($figure->style()->maxWidth), 1e-9);
        $image = $figure->children()[0];
        self::assertInstanceOf(Image::class, $image);
        self::assertEqualsWithDelta(px(100)->points, self::points($image->style()->width), 1e-9);
        self::assertNull($image->style()->height);
        self::assertSame([], $original->notes());
    }

    public function test_original_mode_reports_float_and_dropped_css(): void
    {
        $original = new MarkupOptions(styles: StyleMode::Original);
        $t = Markup::html('<p style="float: right; display: grid">x</p>', $original);
        self::assertSame([
            [FidelityLevel::Approximated, 'markup float (rendered as a block)', 'p[0]'],
            [FidelityLevel::Dropped, 'markup CSS "display: grid"', 'p[0]'],
        ], self::notes($t));
        self::assertSame([], self::notes(Markup::html('<p style="float: right; display: grid">x</p>')));
    }

    public function test_span_styles_in_original_mode(): void
    {
        $t = Markup::html('<p>a <span style="color: #ff0000; font-weight: bold">b</span></p>', new MarkupOptions(styles: StyleMode::Original));
        self::assertInstanceOf(Text::class, $t);
        $b = $t->spanList()[1];
        self::assertSame('b', $b->text);
        self::assertSame('#ff0000', (string) $b->style->color);
        self::assertSame(700, $b->style->fontWeight);
        self::assertSame([], $t->notes());

        $themed = Markup::html('<p>a <span style="color: #ff0000">b</span></p>');
        self::assertInstanceOf(Text::class, $themed);
        self::assertNull($themed->spanList()[1]->style->color);
    }

    public function test_inline_css_overrides_the_tag_and_drops_box_properties(): void
    {
        $t = Markup::html('<p><b style="font-weight: normal; margin: 4px; float: left">a</b><a href="https://e.com" style="color: #008000; text-decoration: none">b</a></p>', new MarkupOptions(styles: StyleMode::Original));
        self::assertInstanceOf(Text::class, $t);
        [$a, $b] = $t->spanList();
        self::assertSame(400, $a->style->fontWeight);
        self::assertSame('#008000', (string) $b->style->color);
        self::assertFalse($b->style->underline);
        self::assertSame('https://e.com', $b->href);
        self::assertSame([
            [FidelityLevel::Dropped, 'markup CSS "margin: 4px"', 'p[0] > b[0]'],
            [FidelityLevel::Dropped, 'markup CSS "float: left"', 'p[0] > b[0]'],
        ], self::notes($t));
    }

    public function test_block_styles_land_on_the_nodes_the_element_produced(): void
    {
        $original = new MarkupOptions(styles: StyleMode::Original);

        $t = Markup::html('<p style="text-align: center; color: #00f">x</p>', $original);
        self::assertInstanceOf(Text::class, $t);
        self::assertSame(TextAlign::Center, $t->style()->textAlign);
        self::assertSame('#0000ff', (string) $t->style()->color);

        $v = Markup::html('<div style="background-color: #eee; padding: 8px"><p>a</p><p>b</p></div><p>c</p>', $original);
        self::assertInstanceOf(View::class, $v);
        [$box, $c] = $v->children();
        self::assertInstanceOf(View::class, $box);
        self::assertCount(2, $box->children());
        self::assertSame('#eeeeee', (string) $box->style()->background);
        self::assertEqualsWithDelta(6.0, $box->style()->padding?->top->points ?? 0, 1e-9);
        self::assertInstanceOf(Text::class, $c);
        self::assertTrue($c->style()->isEmpty());

        $v = Markup::html('<h2 style="color: #f00">T</h2><p>x</p>', $original);
        self::assertInstanceOf(View::class, $v);
        $h = $v->children()[0];
        self::assertInstanceOf(Text::class, $h);
        self::assertSame('h2', $h->preset());
        self::assertSame('#ff0000', (string) $h->style()->color);
    }

    public function test_list_styles_in_original_mode(): void
    {
        $original = new MarkupOptions(styles: StyleMode::Original);
        $v = Markup::html('<ol type="a" style="list-style-type: upper-roman; margin: 0"><li style="color: #f00; list-style-type: disc">a</li></ol>', $original);
        self::assertInstanceOf(View::class, $v);
        $list = $v->children()[0];
        self::assertInstanceOf(ListNode::class, $list);
        self::assertSame(ListMarker::UpperRoman, $list->markerStyle());
        self::assertSame(0.0, $list->style()->margin?->top->points);
        self::assertSame('#ff0000', (string) $list->items()[0]->style()->color);
        self::assertSame([[FidelityLevel::Dropped, 'markup CSS "list-style-type: disc"', 'ol[0] > li[0]']], self::notes($v));

        $u = Markup::html('<ul style="list-style-type: none"><li>a</li></ul>', $original);
        self::assertInstanceOf(View::class, $u);
        $list = $u->children()[0];
        self::assertInstanceOf(ListNode::class, $list);
        self::assertSame(ListMarker::None, $list->markerStyle());
    }

    public function test_figcaption_style_and_list_item_paragraph_keep_their_style(): void
    {
        $original = new MarkupOptions(styles: StyleMode::Original);
        $v = Markup::html('<figure><p>x</p><figcaption style="text-align: right">cap</figcaption></figure>', $original);
        self::assertInstanceOf(View::class, $v);
        $figure = $v->children()[0];
        self::assertInstanceOf(View::class, $figure);
        $caption = $figure->children()[1];
        self::assertInstanceOf(Text::class, $caption);
        self::assertSame(TextAlign::Right, $caption->style()->textAlign);

        $v = Markup::html('<ul><li><p style="color: #f00">a</p></li></ul>', $original);
        self::assertInstanceOf(View::class, $v);
        $list = $v->children()[0];
        self::assertInstanceOf(ListNode::class, $list);
        $text = $list->items()[0]->children()[0];
        self::assertInstanceOf(Text::class, $text);
        self::assertNull($text->preset());
        self::assertSame('#ff0000', (string) $text->style()->color);
    }

    public function test_an_item_joined_by_a_loose_list_keeps_its_style(): void
    {
        $v = Markup::html('<ul><li style="color: #ff0000">a</li><ul><li>n</li></ul></ul>', new MarkupOptions(styles: StyleMode::Original));
        self::assertInstanceOf(View::class, $v);
        $list = $v->children()[0];
        self::assertInstanceOf(ListNode::class, $list);
        self::assertCount(1, $list->items());
        self::assertCount(2, $list->items()[0]->children());
        self::assertSame('#ff0000', (string) $list->items()[0]->style()->color);
    }

    private static function points(mixed $v): float
    {
        self::assertInstanceOf(Length::class, $v);
        return $v->points;
    }

    /** @return list<array{FidelityLevel, string, string}> */
    private static function notes(View|Text $n): array
    {
        return array_map(fn (Note $x) => [$x->level, $x->feature, $x->where], $n->notes());
    }

    /** @return list<Text> */
    private static function texts(View $v): array
    {
        $out = [];
        foreach ($v->children() as $child) {
            self::assertInstanceOf(Text::class, $child);
            $out[] = $child;
        }
        return $out;
    }

    /**
     * @param list<Span> $spans
     * @return list<string>
     */
    private static function spanTexts(array $spans): array
    {
        return array_map(fn (Span $s) => $s->text, $spans);
    }
}
