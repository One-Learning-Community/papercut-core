<?php

declare(strict_types=1);

namespace Papercut\Tests\Feature;

use Papercut\{Document, OverflowMode, Page, Place, Table, Text, Toc};
use Papercut\Layout\{LayoutContext, LayoutEngine, Paginator};
use Papercut\Paint\{PageDisplayList, Painter, TextRun};
use Papercut\Style\{PageThemes, Style, Theme, ThemeOverride};
use Papercut\Tests\Support\FakeBackend;
use Papercut\Text\FontRegistry;
use Papercut\Toc\TocIndex;
use Papercut\Value\Color;
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

/** Theme overrides on a document and its pages, through PDF layout. */
final class ThemeOverridePdfTest extends TestCase
{
    /** @return list<PageDisplayList> */
    private static function paint(Document $doc): array
    {
        $b = new FakeBackend();
        $themes = PageThemes::for(Theme::default(), $doc);
        $ctx = new LayoutContext(FontRegistry::withDefaults($b), $themes->document(), $b, OverflowMode::Throw, null);
        $ctx->themes = $themes;
        $ctx->toc = TocIndex::collect($doc, $themes);
        return Painter::paint((new Paginator(new LayoutEngine($ctx), $ctx))->paginate($doc));
    }

    /** @return list<TextRun> */
    private static function runs(PageDisplayList $p): array
    {
        return array_values(array_filter($p->ops, static fn ($o): bool => $o instanceof TextRun));
    }

    private static function page(): Page
    {
        return Page::size(pt(300), pt(200))->margin(pt(20));
    }

    public function test_document_override_changes_body_size(): void
    {
        $doc = Document::make(self::page()->body('Hello'))->theme(new ThemeOverride(fontSize: pt(14)));
        $runs = self::runs(self::paint($doc)[0]);
        self::assertNotEmpty($runs);
        self::assertSame(14.0, $runs[0]->size);
    }

    public function test_page_override_applies_to_body_header_footer_background_and_overlay(): void
    {
        $red = Color::parse('red-500');
        $page = self::page()->theme(new ThemeOverride(color: $red))
            ->header('Head')->footer('Foot')->background('Back')
            ->overlay(Place::at(pt(10), pt(10), 'Stamp'))->body('Body');
        $runs = self::runs(self::paint(Document::make($page))[0]);
        $texts = array_map(static fn (TextRun $r): string => $r->text, $runs);
        foreach (['Head', 'Foot', 'Back', 'Stamp', 'Body'] as $want) {
            self::assertContains($want, $texts);
        }
        foreach ($runs as $r) {
            self::assertEquals($red, $r->color, $r->text);
        }
    }

    public function test_a_page_without_override_after_one_with_it_uses_the_document_theme(): void
    {
        $doc = Document::make(
            self::page()->theme(new ThemeOverride(fontSize: pt(16)))->body('A'),
            self::page()->body('B'),
        );
        $pages = self::paint($doc);
        self::assertSame(16.0, self::runs($pages[0])[0]->size);
        self::assertSame(10.0, self::runs($pages[1])[0]->size);
    }

    public function test_look_ahead_header_variants_use_the_page_theme(): void
    {
        $page = self::page()->theme(new ThemeOverride(color: Color::parse('red-500')))
            ->firstHeader('First')->evenHeader('Even')->header('Run')
            ->body(...array_map(static fn (int $i) => Text::make("L$i"), range(1, 40)));
        $pages = self::paint(Document::make($page));
        self::assertGreaterThan(2, count($pages));
        foreach ($pages as $p) {
            foreach (self::runs($p) as $r) {
                self::assertEquals(Color::parse('red-500'), $r->color);
            }
        }
    }

    public function test_same_node_on_two_pages_resolves_per_theme(): void
    {
        $text = Text::make('Same');
        $table = Table::make()->columns('1fr 1fr')->row('A', 'B');
        $doc = Document::make(
            self::page()->body($text, $table),
            self::page()->theme(new ThemeOverride(fontSize: pt(18), cellPadding: \Papercut\Value\Edges::all(pt(12))))->body($text, $table),
        );
        $pages = self::paint($doc);
        $one = self::runs($pages[0]);
        $two = self::runs($pages[1]);
        self::assertSame(10.0, $one[0]->size);
        self::assertSame(18.0, $two[0]->size);
        self::assertGreaterThan($one[1]->x + 5.0, $two[1]->x);
    }

    public function test_toc_lists_headings_from_pages_with_other_themes(): void
    {
        $doc = Document::make(
            self::page()->body(Toc::make()->levels(1, 1)),
            self::page()->theme(new ThemeOverride(fontSize: pt(20), styles: ['h1' => Style::make(fontSize: pt(30)), 'toc.entry' => Style::make(fontSize: pt(20))]))->body(Text::h1('Alpha')),
        );
        $plain = self::paint(Document::make(
            self::page()->body(Toc::make()->levels(1, 1)),
            self::page()->body(Text::h1('Alpha')),
        ));
        $defaultEntry = 0.0;
        foreach (self::runs($plain[0]) as $r) {
            if ($r->text === 'Alpha') {
                $defaultEntry = $r->size;
            }
        }
        self::assertGreaterThan(0.0, $defaultEntry);
        $pages = self::paint($doc);
        $tocRuns = self::runs($pages[0]);
        $texts = array_map(static fn (TextRun $r): string => $r->text, $tocRuns);
        self::assertContains('Alpha', $texts);
        self::assertContains('2', $texts);
        foreach ($tocRuns as $r) {
            if ($r->text === 'Alpha') {
                self::assertSame($defaultEntry, $r->size);
            }
        }
        self::assertSame(30.0, self::runs($pages[1])[0]->size);
    }

    public function test_h1_override_on_a_page_changes_only_that_page(): void
    {
        $doc = Document::make(
            self::page()->body(Text::h1('One')),
            self::page()->theme(new ThemeOverride(styles: ['h1' => Style::make(fontSize: pt(40))]))->body(Text::h1('Two')),
            self::page()->body(Text::h1('Three')),
        );
        $pages = self::paint($doc);
        $a = self::runs($pages[0])[0]->size;
        self::assertSame(40.0, self::runs($pages[1])[0]->size);
        self::assertSame($a, self::runs($pages[2])[0]->size);
        self::assertNotSame(40.0, $a);
    }

    public function test_no_overrides_is_byte_identical(): void
    {
        $make = static fn (): Document => Document::make(self::page()->body(Text::h1('T'), Text::make('Body text')))
            ->createdAt(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $plain = $make()->toPdf()->bytes();
        $empty = $make()->theme(new ThemeOverride())->toPdf()->bytes();
        self::assertSame($plain, $empty);
    }

    public function test_shared_table_with_padding_only_override_matches_a_fresh_one(): void
    {
        $pad = new ThemeOverride(cellPadding: \Papercut\Value\Edges::all(pt(15)));
        $make = static fn (): Table => Table::make()->columns('auto auto')->row('Aaaa', 'B');
        $shared = $make();
        $doc = Document::make(self::page()->body($shared), self::page()->theme($pad)->body($shared));
        $fresh = Document::make(self::page()->theme($pad)->body($make()));
        $got = self::runs(self::paint($doc)[1]);
        $want = self::runs(self::paint($fresh)[0]);
        self::assertSame(array_map(static fn (TextRun $r): array => [$r->text, $r->x], $want), array_map(static fn (TextRun $r): array => [$r->text, $r->x], $got));
    }

    public function test_shared_text_with_span_style_only_override_matches_a_fresh_one(): void
    {
        $red = Color::parse('red-500');
        $over = new ThemeOverride(styles: ['code' => Style::make(color: $red)]);
        $make = static fn (): Text => Text::spans(\Papercut\Span::text('Plain '), \Papercut\Span::code('mono'));
        $shared = $make();
        $doc = Document::make(self::page()->body($shared), self::page()->theme($over)->body($shared));
        $colors = static fn (PageDisplayList $p): array => array_map(static fn (TextRun $r): string => $r->text . (string) json_encode($r->color), self::runs($p));
        $got = $colors(self::paint($doc)[1]);
        $want = $colors(self::paint(Document::make(self::page()->theme($over)->body($make())))[0]);
        self::assertSame($want, $got);
        self::assertNotSame($colors(self::paint($doc)[0]), $got);
    }

    public function test_a_freed_theme_never_lends_its_token_to_a_live_one(): void
    {
        $b = new FakeBackend();
        $pages = array_map(static fn (int $n): Page => self::page()->theme(new ThemeOverride(fontSize: pt(10 + $n)))->body('x'), [1, 2, 3]);
        $doc = Document::make(...$pages);
        $themes = PageThemes::for(Theme::default(), $doc);
        $ctx = new LayoutContext(FontRegistry::withDefaults($b), $themes->document(), $b, OverflowMode::Throw, null);
        $ctx->themes = $themes;
        $engine = new LayoutEngine($ctx);
        $key = new \ReflectionMethod($engine->tableLayouter(), 'themeKey');
        $tokens = [];
        foreach ($pages as $page) {
            $ctx->usePage($page);
            $tokens[] = $key->invoke($engine->tableLayouter(), $engine);
        }
        self::assertCount(3, array_unique($tokens));
        // A theme made after another is freed must not collide with a live token.
        $ctx2 = new LayoutContext(FontRegistry::withDefaults($b), Theme::default(), $b, OverflowMode::Throw, null);
        $engine2 = new LayoutEngine($ctx2);
        $layouter = $engine2->tableLayouter();
        $page = self::page()->body('x');
        $tokenOf = function (Theme $t) use ($ctx2, $key, $layouter, $engine2, $page): int {
            $pt = PageThemes::for($t, Document::make($page));
            $ctx2->themes = $pt;
            $ctx2->usePage($page);
            return $key->invoke($layouter, $engine2);
        };
        $a = Theme::default()->withOverride(new ThemeOverride(fontSize: pt(21)));
        $b2 = Theme::default()->withOverride(new ThemeOverride(fontSize: pt(22)));
        $tokenOf($a);
        $tokenB = $tokenOf($b2); // the context now holds b2 only
        unset($a);
        gc_collect_cycles();
        $c = Theme::default()->withOverride(new ThemeOverride(fontSize: pt(23)));
        self::assertNotSame($tokenB, $tokenOf($c));
    }
}
