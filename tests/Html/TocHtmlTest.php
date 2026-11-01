<?php

declare(strict_types=1);

namespace Papercut\Tests\Html;

use Papercut\{Document, Page, Text, Toc};
use Papercut\Export\{FidelityReport, HtmlOptions};
use PHPUnit\Framework\TestCase;

final class TocHtmlTest extends TestCase
{
    /** @param list<string> $messages */
    private function dom(Document $doc, array &$messages = []): \Dom\HTMLDocument
    {
        $messages = [];
        $html = $doc->toHtml(new HtmlOptions(fullPage: true, onWarning: function (string $m, ?FidelityReport $r = null) use (&$messages): void {
            $messages[] = $m;
        }))->bytes();
        return \Dom\HTMLDocument::createFromString($html);
    }

    /** @return list<string> */
    private function ids(\Dom\HTMLDocument $dom): array
    {
        $ids = [];
        foreach ($dom->querySelectorAll('h1, h2, h3, h4') as $h) {
            $ids[] = (string) $h->getAttribute('id');
        }
        return $ids;
    }

    public function test_entry_headings_get_ids_and_others_do_not(): void
    {
        $dom = $this->dom(Document::make(Page::a4()->body(
            Toc::make()->levels(1, 2),
            Text::h1('Getting Started'),
            Text::h2('Packing & Gear!'),
            Text::h3('Too deep'),
            Text::h1('   '),
        )));
        self::assertSame(['getting-started', 'packing-gear', '', ''], $this->ids($dom));
    }

    public function test_no_ids_without_a_toc(): void
    {
        $dom = $this->dom(Document::make(Page::a4()->body(Text::h1('One'), Text::h2('Two'))));
        self::assertSame(['', ''], $this->ids($dom));
        self::assertNull($dom->querySelector('[id]'));
    }

    public function test_clashing_slugs_count_up_in_document_order(): void
    {
        $dom = $this->dom(Document::make(Page::a4()->body(
            Text::h1('Setup'),
            Text::h2('Setup'),
            Text::h2('Setup 2'),
            Text::h2('Setup'),
            Text::h1('???'),
            Toc::make(),
        )));
        self::assertSame(['setup', 'setup-2', 'setup-2-2', 'setup-3', 'section'], $this->ids($dom));
    }

    public function test_nav_links_match_ids_and_nest_by_level(): void
    {
        $dom = $this->dom(Document::make(Page::a4()->body(
            Toc::make()->title('Contents')->levels(1, 2),
            Text::h1('Alpha'),
            Text::h2('Beta'),
            Text::h2('Gamma'),
            Text::h1('Delta'),
        )));
        $nav = $dom->querySelector('nav.papercut-toc');
        self::assertNotNull($nav);
        self::assertSame('Contents', $nav->querySelector('p.papercut-toc-title')?->textContent);
        $top = $nav->querySelectorAll('nav > ol > li > a');
        self::assertSame(['#alpha', '#delta'], array_map(static fn ($a): string => (string) $a->getAttribute('href'), iterator_to_array($top)));
        $nested = $nav->querySelectorAll('nav > ol > li > ol > li > a');
        self::assertSame(['#beta', '#gamma'], array_map(static fn ($a): string => (string) $a->getAttribute('href'), iterator_to_array($nested)));
        self::assertSame('Beta', $nested[0]->textContent);
        self::assertSame($this->ids($dom), ['alpha', 'beta', 'gamma', 'delta']);
    }

    public function test_page_number_approximation_is_reported_once_per_toc(): void
    {
        $messages = [];
        $this->dom(Document::make(Page::a4()->body(
            Toc::make(),
            Text::h1('A'),
            Toc::make()->levels(1, 1),
        )), $messages);
        $found = array_values(array_filter($messages, static fn (string $m): bool => str_contains($m, 'table of contents page numbers')));
        self::assertCount(2, $found);
        self::assertNotSame($found[0], $found[1]);
    }

    public function test_toc_without_entries_writes_the_title_only(): void
    {
        $dom = $this->dom(Document::make(Page::a4()->body(Toc::make()->title('Contents'), Text::make('plain'))));
        self::assertNotNull($dom->querySelector('nav.papercut-toc p.papercut-toc-title'));
        self::assertNull($dom->querySelector('nav.papercut-toc ol'));
    }

    public function test_nesting_after_a_skipped_level(): void
    {
        $dom = $this->dom(Document::make(Page::a4()->body(
            Toc::make()->levels(1, 3),
            Text::h1('A'),
            Text::h3('C'),
            Text::h2('B'),
            Text::h1('D'),
        )));
        $nav = $dom->querySelector('nav.papercut-toc');
        self::assertNotNull($nav);
        $shape = preg_replace('/ (style|href)="[^"]*"/', '', $dom->saveHtml($nav));
        self::assertSame(
            '<nav class="papercut-toc"><ol><li><a>A</a><ol><li><a>C</a></li><li><a>B</a></li></ol></li><li><a>D</a></li></ol></nav>',
            $shape,
        );
    }

    public function test_slugs_keep_unicode_letters(): void
    {
        $dom = $this->dom(Document::make(Page::a4()->body(
            Toc::make(),
            Text::h1('Café au lait'),
            Text::h1('日本語の見出し'),
        )));
        self::assertSame(['café-au-lait', '日本語の見出し'], $this->ids($dom));
    }

    public function test_slugs_keep_combining_marks(): void
    {
        $dom = $this->dom(Document::make(Page::a4()->body(
            Toc::make(),
            Text::h1('हिन्दी शीर्षक'),
            Text::h1("cafe\u{301} noir"),
        )));
        self::assertSame(['हिन्दी-शीर्षक', "cafe\u{301}-noir"], $this->ids($dom));
    }
}
