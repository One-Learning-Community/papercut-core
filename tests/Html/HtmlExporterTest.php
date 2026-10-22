<?php

declare(strict_types=1);

namespace Papercut\Tests\Html;

use Papercut\{Column, Document, Link, Page, PageBreak, PageNumber, Row, Span, Spacer, Text, View};
use Papercut\Export\{FidelityReport, HtmlOptions};
use Papercut\Exception\InvalidValue;
use Papercut\Style\{Justify, Style};
use Papercut\Value\Color;
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

final class HtmlExporterTest extends TestCase
{
    private function html(Document $doc, ?HtmlOptions $o = null): string
    {
        return $doc->toHtml($o)->bytes();
    }

    private function parse(string $html): \Dom\HTMLDocument
    {
        if (!str_starts_with($html, '<!DOCTYPE')) {
            $html = '<!DOCTYPE html><html><head><title>t</title></head><body>' . $html . '</body></html>';
        }
        set_error_handler(static function (int $no, string $msg): never {
            throw new \RuntimeException($msg);
        });
        try {
            return \Dom\HTMLDocument::createFromString($html);
        } finally {
            restore_error_handler();
        }
    }

    /** @return list<\Dom\Node> */
    private function xpath(string $html, string $query): array
    {
        $nodes = [];
        $xp = new \Dom\XPath($this->parse($html));
        $xp->registerNamespace('h', 'http://www.w3.org/1999/xhtml');
        $query = (string) preg_replace('~(?<=/)([a-z][a-z0-9]*)(?![a-z0-9(\-])~', 'h:$1', $query);
        foreach ($xp->query($query) as $n) {
            $nodes[] = $n;
        }
        return $nodes;
    }

    private function one(string $html, string $query): \Dom\Element
    {
        $found = $this->xpath($html, $query);
        self::assertCount(1, $found, $query);
        self::assertInstanceOf(\Dom\Element::class, $found[0]);
        return $found[0];
    }

    private function attr(\Dom\Node $e, string $name): string
    {
        self::assertInstanceOf(\Dom\Element::class, $e);

        return (string) $e->getAttribute($name);
    }

    private function simple(): Document
    {
        return Document::make(Page::a4()->body(Text::make('Hello')));
    }

    public function test_fragment_and_full_page_shapes(): void
    {
        $doc = $this->simple()->title('T')->lang('fr');
        $out = $doc->toHtml();
        self::assertSame('text/html; charset=UTF-8', $out->mime());
        self::assertSame('html', $out->extension());
        self::assertStringStartsWith('<style>', $out->bytes());
        self::assertStringContainsString('</style><div class="papercut" lang="fr" style="', $out->bytes());
        self::assertStringNotContainsString('<!DOCTYPE', $out->bytes());

        $full = $this->html($doc, new HtmlOptions(fullPage: true));
        self::assertStringStartsWith('<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8">', $full);
        self::assertSame('T', $this->one($full, '//head/title')->textContent);
        self::assertCount(1, $this->xpath($full, '//head/style'));
        self::assertCount(1, $this->xpath($full, '//body/div[@class="papercut"]/section[@class="pc-page"]'));
        self::assertStringContainsString('<html lang="en">', $this->html($this->simple(), new HtmlOptions(fullPage: true)));
    }

    public function test_row_is_flex_row_with_gap_and_justify(): void
    {
        $doc = Document::make(Page::a4()->body(Row::make(Text::make('a'), Text::make('b'))->gap(pt(8))->justify(Justify::SpaceBetween)));
        $row = $this->one($this->html($doc), '//div[@class="pc-body"]/div');
        $style = $this->attr($row, 'style');
        self::assertStringContainsString('display:flex', $style);
        self::assertStringContainsString('flex-direction:row', $style);
        self::assertStringContainsString('gap:8pt', $style);
        self::assertStringContainsString('justify-content:space-between', $style);
    }

    public function test_text_presets_map_to_headings_and_paragraphs(): void
    {
        $doc = Document::make(Page::a4()->body(Text::h1('A'), Text::h2('B'), Text::h3('C'), Text::h4('D'), Text::p('E'), Text::make('F')));
        $html = $this->html($doc);
        foreach (['h1', 'h2', 'h3', 'h4'] as $tag) {
            self::assertCount(1, $this->xpath($html, "//div[@class='pc-body']/$tag"));
        }
        self::assertCount(2, $this->xpath($html, "//div[@class='pc-body']/p"));
    }

    public function test_spans_emit_diffs_links_and_breaks(): void
    {
        $doc = Document::make(Page::a4()->body(Text::spans(
            Span::text('plain '),
            Span::text('em', new Style(italic: true)),
            Span::break(),
            new Span('go', new Style(), 'https://example.com/?a=1&b=2'),
        )));
        $html = $this->html($doc);
        $p = $this->one($html, '//div[@class="pc-body"]/p');
        self::assertSame('plain emgo', $p->textContent);
        self::assertStringContainsString('font-style:italic', $this->attr($this->one($html, '//p/span'), 'style'));
        self::assertCount(1, $this->xpath($html, '//p/br'));
        self::assertSame('https://example.com/?a=1&b=2', $this->attr($this->one($html, '//p/a'), 'href'));
        self::assertCount(1, $this->xpath($html, '//p/text()[starts-with(., "plain")]'));
    }

    public function test_transparent_span_background_emits_no_css(): void
    {
        $doc = Document::make(Page::a4()->body(Text::spans(
            Span::text('hot', Style::make(background: Color::transparent())),
        )));
        self::assertStringNotContainsString('background-color', $this->html($doc));
    }

    public function test_highlighted_span_emits_background_color(): void
    {
        $doc = Document::make(Page::a4()->body(Text::spans(
            Span::text('plain '),
            Span::text('hot', Style::make(background: Color::named('yellow-200'))),
        )));
        $html = $this->html($doc);
        self::assertSame('background-color:#fef08a', $this->attr($this->one($html, '//p/span'), 'style'));
        self::assertCount(1, $this->xpath($html, '//p/span'));
    }

    public function test_block_link_and_spacers_and_flex_item(): void
    {
        $doc = Document::make(Page::a4()->body(Column::make(
            Link::to('#top', Text::make('x')),
            Spacer::size(pt(12)),
            Spacer::flex(2),
            Text::make('g')->grow(1),
        )));
        $html = $this->html($doc);
        $a = $this->one($html, '//a[@class="pc-link"]');
        self::assertSame('#top', $this->attr($a, 'href'));
        self::assertStringStartsWith('display:flex;flex-direction:column', $this->attr($a, 'style'));
        $spacers = $this->xpath($html, '//div[@class="pc-body"]/div/div');
        self::assertCount(2, $spacers);
        self::assertSame('flex:0 0 12pt', $this->attr($spacers[0], 'style'));
        self::assertSame('flex:2 0 0', $this->attr($spacers[1], 'style'));
        self::assertStringContainsString('flex:1 1 auto', $this->attr($this->one($html, '//div[@class="pc-body"]/div/p'), 'style'));
    }

    public function test_page_number_is_omitted_silently(): void
    {
        $reports = [];
        $doc = Document::make(Page::a4()->body(Text::make('x'))->footer(PageNumber::make('Page {page}')));
        $html = $this->html($doc, new HtmlOptions(onWarning: function (string $m) use (&$reports): void {
            $reports[] = $m;
        }));
        self::assertCount(0, $this->xpath($html, '//div[@class="pc-footer"]/*'));
        self::assertSame('', $this->one($html, '//div[@class="pc-footer"]')->textContent);
        self::assertStringNotContainsString('Page 1', (string) $this->parse($html)->body?->textContent);
        foreach ($reports as $m) {
            self::assertStringNotContainsString('page-number', $m);
        }
    }

    public function test_page_break_and_keep_rules_become_print_css(): void
    {
        $doc = Document::make(Page::a4()->body(
            Text::make('a')->keepTogether()->breakBefore()->breakAfter()->minLines(3),
            PageBreak::make(),
            Text::make('b'),
        ));
        $html = $this->html($doc);
        $style = $this->attr($this->one($html, '//div[@class="pc-body"]/p[1]'), 'style');
        foreach (['break-before:page', 'break-after:page', 'break-inside:avoid', 'orphans:3', 'widows:3'] as $d) {
            self::assertStringContainsString($d, $style);
        }
        self::assertCount(1, $this->xpath($html, '//div[@class="pc-body"]/div[@class="pc-break"]'));
    }

    public function test_header_footer_background_regions_and_reports(): void
    {
        $messages = [];
        $doc = Document::make(Page::a4()->header(Text::make('H'))->footer(Text::make('F'))->background(Text::make('B'))->body(Text::make('x')));
        $html = $this->html($doc, new HtmlOptions(onWarning: function (string $m, ?FidelityReport $r = null) use (&$messages): void {
            $messages[] = $m;
        }));
        self::assertSame([
            'html: approximated page box at page',
            'html: approximated header and footer once per page at page',
            'html: approximated background layer at page',
        ], $messages);
        $regions = array_map(static fn (\Dom\Node $n): string => $n instanceof \Dom\Element ? $n->getAttribute('class') ?? '' : '', $this->xpath($html, '//section/div'));
        self::assertSame(['pc-background', 'pc-header', 'pc-body', 'pc-footer'], $regions);
        self::assertStringContainsString('margin-bottom:', $this->attr($this->one($html, '//div[@class="pc-header"]'), 'style'));
        self::assertStringContainsString('margin-top:', $this->attr($this->one($html, '//div[@class="pc-footer"]'), 'style'));
    }

    public function test_page_paths_are_indexed_for_several_pages(): void
    {
        $messages = [];
        $doc = Document::make(Page::a4()->body(Text::make('a')), Page::a4()->footer(Text::make('f'))->body(Text::make('b')));
        $this->html($doc, new HtmlOptions(onWarning: function (string $m) use (&$messages): void {
            $messages[] = $m;
        }));
        self::assertSame([
            'html: approximated page box at page[0]',
            'html: approximated page box at page[1]',
            'html: approximated header and footer once per page at page[1]',
        ], $messages);
    }

    public function test_underline_override_is_approximated(): void
    {
        $messages = [];
        $doc = Document::make(Page::a4()->body(
            Column::make(Text::make('a'), Text::spans(Span::text('x', new Style(underline: false)), Span::text('y', new Style(underline: false))))->underline(),
        ));
        $html = $this->html($doc, new HtmlOptions(onWarning: function (string $m) use (&$messages): void {
            $messages[] = $m;
        }));
        self::assertContains('html: approximated text decoration override at page > body > column[0] > text[1]', $messages);
        self::assertCount(1, array_filter($messages, static fn (string $m): bool => str_contains($m, 'decoration')));
        self::assertCount(2, $this->xpath($html, '//span[contains(@style, "text-decoration:none")]'));
    }

    public function test_strict_mode_never_throws_for_html(): void
    {
        $doc = Document::make(Page::a4()->header(Text::make('H'))->background(Text::make('B'))->body(Text::make('x'))->footer(PageNumber::make()));
        self::assertNotSame('', $this->html($doc, new HtmlOptions(strict: true)));
    }

    public function test_hostile_text_is_escaped(): void
    {
        $evil = '<script>alert(1)</script><img src=x onerror=alert(1)>"\'&';
        $doc = Document::make(Page::a4()->body(Text::make($evil)))->title($evil)->author($evil)->subject($evil)->keywords($evil)->lang('en"><script>');
        foreach ([null, new HtmlOptions(fullPage: true)] as $o) {
            $html = $this->html($doc, $o);
            self::assertStringNotContainsString('<script', $html);
            self::assertStringNotContainsString('<img', $html);
            self::assertSame($evil, $this->one($html, '//div[@class="pc-body"]/p')->textContent);
        }
        $full = $this->html($doc, new HtmlOptions(fullPage: true));
        self::assertSame($evil, $this->one($full, '//title')->textContent);
        self::assertSame($evil, $this->attr($this->one($full, '//meta[@name="author"]'), 'content'));
    }

    public function test_hostile_href_attribute_is_escaped(): void
    {
        $doc = Document::make(Page::a4()->body(Link::to('https://a.test/"><script>x</script>', Text::make('t'))));
        $html = $this->html($doc);
        self::assertStringNotContainsString('<script', $html);
        self::assertSame('https://a.test/"><script>x</script>', $this->attr($this->one($html, '//a'), 'href'));
    }

    public function test_disallowed_href_throws_like_pdf(): void
    {
        foreach ([
            Text::spans(new Span('x', href: 'javascript:alert(1)')),
            Link::to('javascript:alert(1)', Text::make('x')),
        ] as $node) {
            $doc = Document::make(Page::a4()->body($node));
            try {
                $doc->toPdf();
                self::fail('expected InvalidValue');
            } catch (InvalidValue $pdfError) {
                $pdf = $pdfError->getMessage();
            }
            try {
                $doc->toHtml();
                self::fail('expected InvalidValue');
            } catch (InvalidValue $htmlError) {
                self::assertSame($pdf, $htmlError->getMessage());
            }
        }
    }

    public function test_hostile_font_family_stays_inert(): void
    {
        $doc = Document::make(Page::a4()->body(Text::make('x')->font('Evil"; background:url(x)</style><b>')));
        $html = $this->html($doc);
        self::assertCount(0, $this->xpath($html, '//b'));
        self::assertCount(1, $this->xpath($html, '//style'));
        $p = $this->one($html, '//div[@class="pc-body"]/p');
        self::assertStringNotContainsString('</style>', $this->attr($p, 'style'));
        self::assertCount(1, $this->xpath($html, '//div[@class="pc-body"]/p[@style]'));
        self::assertSame('x', $p->textContent);
    }

    public function test_fragment_root_has_no_lang_without_document_lang(): void
    {
        self::assertNull($this->one($this->html($this->simple()), '//div[@class="papercut"]')->getAttribute('lang'));
    }

    public function test_entities_are_escaped_once(): void
    {
        $doc = Document::make(Page::a4()->body(Text::make('a &amp; b &lt; c')));
        self::assertStringContainsString('a &amp;amp; b &amp;lt; c', $this->html($doc));
        self::assertSame('a &amp; b &lt; c', $this->one($this->html($doc), '//p')->textContent);
    }

    public function test_empty_body_and_header_only_pages_are_valid(): void
    {
        $html = $this->html(Document::make(Page::a4()), new HtmlOptions(fullPage: true));
        self::assertCount(1, $this->xpath($html, '//section[@class="pc-page"]'));
        self::assertCount(0, $this->xpath($html, '//section/div'));

        $html = $this->html(Document::make(Page::a4()->header(Text::make('H'))));
        self::assertCount(1, $this->xpath($html, '//section/div[@class="pc-header"]'));
        self::assertCount(0, $this->xpath($html, '//div[@class="pc-body"]'));

        self::assertStringContainsString('<div class="papercut"', $this->html(Document::make()));
    }

    public function test_metadata_in_full_page_head(): void
    {
        $doc = $this->simple()->title('Title')->author('Au')->subject('Sub')->keywords('k1, k2');
        $html = $this->html($doc, new HtmlOptions(fullPage: true));
        self::assertSame('Au', $this->attr($this->one($html, '//head/meta[@name="author"]'), 'content'));
        self::assertSame('Sub', $this->attr($this->one($html, '//head/meta[@name="description"]'), 'content'));
        self::assertSame('k1, k2', $this->attr($this->one($html, '//head/meta[@name="keywords"]'), 'content'));
        self::assertSame('width=device-width, initial-scale=1', $this->attr($this->one($html, '//head/meta[@name="viewport"]'), 'content'));
        self::assertCount(0, $this->xpath($this->html($this->simple(), new HtmlOptions(fullPage: true)), '//meta[@name="author"]'));
    }

    public function test_stylesheet_uses_first_page_box(): void
    {
        $html = $this->html($this->simple(), new HtmlOptions(fullPage: true));
        self::assertStringContainsString('@page{size:595.28pt 841.89pt;margin:56.693pt 56.693pt 56.693pt 56.693pt}', $html);
        self::assertStringContainsString('max-width:595.28pt;min-height:841.89pt;padding:56.693pt', $html);
        self::assertStringContainsString('.papercut tr{break-inside:avoid}', $html);
    }

    public function test_fragment_style_has_no_page_rule(): void
    {
        self::assertStringNotContainsString('@page', $this->html($this->simple()));
    }
}
