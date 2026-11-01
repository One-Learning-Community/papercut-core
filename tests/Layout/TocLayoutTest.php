<?php

declare(strict_types=1);

namespace Papercut\Tests\Layout;

use Papercut\{Document, Page, PageNumber, Text, Toc};
use Papercut\Layout\{Fragment, LayoutContext, LayoutEngine, PlacedPage, Paginator};
use Papercut\OverflowMode;
use Papercut\Paint\{Anchor, Op, TextRun, TocSlot};
use Papercut\Style\{Style, Theme};
use Papercut\Value\Length;
use Papercut\Tests\Support\FakeBackend;
use Papercut\Text\FontRegistry;
use Papercut\Toc\TocIndex;
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

final class TocLayoutTest extends TestCase
{
    /** @var list<string> */
    private array $warnings = [];

    /** @return list<PlacedPage> */
    private function place(Document $doc, bool $bookmarks = true, ?Theme $theme = null): array
    {
        $b = new FakeBackend();
        $ctx = new LayoutContext(FontRegistry::withDefaults($b), $theme ?? Theme::default(), $b, OverflowMode::Throw, function (string $w): void { $this->warnings[] = $w; });
        $ctx->toc = TocIndex::collect($doc, $ctx->styles);
        $ctx->bookmarks = $bookmarks;
        return (new Paginator(new LayoutEngine($ctx), $ctx))->paginate($doc);
    }

    /** @return list<Op> */
    private static function ops(Fragment $f): array
    {
        $ops = $f->ops();
        foreach ($f->children() as $c) {
            array_push($ops, ...self::ops($c['fragment']));
        }
        return $ops;
    }

    /** @return list<TocSlot> */
    private static function slots(Fragment $f): array
    {
        $slots = array_values(array_filter($f->slots(), static fn ($s): bool => $s instanceof TocSlot));
        foreach ($f->children() as $c) {
            array_push($slots, ...self::slots($c['fragment']));
        }
        return $slots;
    }

    /** The fragment that carries the first table of contents slot found under $f. */
    private static function owner(Fragment $f): Fragment
    {
        foreach ($f->slots() as $s) {
            if ($s instanceof TocSlot) {
                return $f;
            }
        }
        foreach ($f->children() as $c) {
            $found = self::owner($c['fragment']);
            if (self::slots($found) !== []) {
                return $found;
            }
        }
        return $f;
    }

    /**
     * @param list<PlacedPage> $pages
     * @return list<Fragment>
     */
    private static function fragments(array $pages): array
    {
        return array_merge(...array_map(static fn (PlacedPage $p): array => array_map(static fn (array $l): Fragment => $l['fragment'], $p->layers), $pages));
    }

    /**
     * @param list<PlacedPage> $pages
     * @return list<Anchor>
     */
    private static function anchors(array $pages): array
    {
        $out = [];
        foreach (self::fragments($pages) as $f) {
            foreach (self::ops($f) as $op) {
                if ($op instanceof Anchor) {
                    $out[] = $op;
                }
            }
        }
        return $out;
    }

    private static function page(): Page
    {
        return Page::size(pt(300), pt(400))->margin(pt(20));
    }

    public function test_anchors_land_only_on_entry_headings(): void
    {
        $doc = Document::make(self::page()->body(Toc::make(), Text::h1('One'), Text::h2('Two'), Text::h3('Three')));
        self::assertSame(['papercut-toc-0', 'papercut-toc-1'], array_map(static fn (Anchor $a): string => $a->name, self::anchors($this->place($doc))));
    }

    public function test_no_anchors_without_a_toc(): void
    {
        $doc = Document::make(self::page()->body(Text::h1('One'), Text::h2('Two')));
        self::assertSame([], self::anchors($this->place($doc)));
    }

    public function test_anchors_do_not_depend_on_bookmarks(): void
    {
        $doc = Document::make(self::page()->body(Toc::make(), Text::h1('One'), Text::h2('Two')));
        $pages = $this->place($doc, bookmarks: false);
        self::assertCount(2, self::anchors($pages));
    }

    public function test_a_heading_in_a_header_gets_no_anchor(): void
    {
        $doc = Document::make(self::page()->header(Text::h1('Running head'))->body(Toc::make(), Text::h1('One')));
        self::assertSame(['papercut-toc-0'], array_map(static fn (Anchor $a): string => $a->name, self::anchors($this->place($doc))));
    }

    public function test_one_slot_per_entry_with_the_reserved_number_width(): void
    {
        $doc = Document::make(self::page()->body(Toc::make()->title('Contents'), Text::h1('One'), Text::h2('Two'), Text::h1('Three')));
        $slots = [];
        foreach (self::fragments($this->place($doc)) as $f) {
            array_push($slots, ...self::slots($f));
        }
        self::assertSame(['papercut-toc-0', 'papercut-toc-1', 'papercut-toc-2'], array_map(static fn (TocSlot $s): string => $s->destination, $slots));
        foreach ($slots as $s) {
            self::assertEqualsWithDelta($s->font->advance('999', $s->size, $s->letterSpacing), $s->width, 1e-6);
            self::assertEqualsWithDelta($s->boxWidth, $s->x + $s->width, 1e-6);
            self::assertTrue($s->leaders);
            self::assertGreaterThan(0.0, $s->boxHeight);
        }
    }

    public function test_a_wrapped_entry_puts_the_number_on_its_last_line(): void
    {
        $long = 'A heading long enough that it has to wrap onto a second line of the contents';
        $doc = Document::make(Page::size(pt(200), pt(400))->margin(pt(0))->body(Toc::make(), Text::h1($long)));
        $f = self::owner(self::fragments($this->place($doc))[0]);
        $slots = self::slots($f);
        self::assertCount(1, $slots);
        $runs = array_values(array_filter(self::ops($f), static fn (Op $o): bool => $o instanceof TextRun));
        $baselines = array_values(array_unique(array_map(static fn (TextRun $r): float => $r->baseline, $runs)));
        self::assertGreaterThan(1, count($baselines));
        $last = max(...$baselines);
        self::assertEqualsWithDelta($last, $slots[0]->baseline, 1e-6);
        foreach ($runs as $r) {
            self::assertLessThanOrEqual($slots[0]->x, $r->x + $r->font->advance($r->text, $r->size, $r->letterSpacing));
        }
        $end = 0.0;
        foreach ($runs as $r) {
            if (abs($r->baseline - $last) < 1e-6) {
                $end = max($end, $r->x + $r->font->advance($r->text, $r->size, $r->letterSpacing));
            }
        }
        self::assertEqualsWithDelta($end, $slots[0]->leaderStart, 1e-6);
    }

    public function test_entries_never_split_across_pages(): void
    {
        $text = static fn (int $n): string => "Heading number $n of a long contents list that is sure to wrap onto a second line on a small page";
        $headings = array_map(static fn (int $i): Text => Text::h2($text($i)), range(1, 80));
        $doc = Document::make(Page::a6()->body(Toc::make()->title('Contents')->levels(1, 2), ...$headings));
        $pages = $this->place($doc);

        $entryPages = [];
        $titlePage = null;
        foreach ($pages as $i => $p) {
            foreach ($p->layers as $l) {
                foreach (self::ownersOf($l['fragment']) as $owner) {
                    $runs = array_values(array_filter(self::ops($owner), static fn (Op $o): bool => $o instanceof TextRun));
                    self::assertGreaterThan(1, count(array_unique(array_map(static fn (TextRun $r): float => $r->baseline, $runs))), 'entry wraps');
                    $entryPages[implode(' ', array_map(static fn (TextRun $r): string => $r->text, $runs))][] = $i;
                }
                foreach (self::ops($l['fragment']) as $op) {
                    if ($op instanceof TextRun && $op->text === 'Contents') {
                        $titlePage = $i;
                    }
                }
            }
        }
        self::assertCount(80, $entryPages);
        foreach (range(1, 80) as $n) {
            self::assertCount(1, $entryPages[$text($n)] ?? [], "entry $n has its lines and its slot on one page");
        }
        self::assertGreaterThan(1, count(array_unique(array_map(static fn (array $p): int => $p[0], $entryPages))));
        self::assertSame($entryPages[$text(1)][0], $titlePage);
    }

    /** @return list<Fragment> the fragments that directly carry a table of contents slot */
    private static function ownersOf(Fragment $f): array
    {
        $out = array_values(array_filter($f->slots(), static fn ($s): bool => $s instanceof TocSlot)) === [] ? [] : [$f];
        foreach ($f->children() as $c) {
            array_push($out, ...self::ownersOf($c['fragment']));
        }
        return $out;
    }

    public function test_the_entry_styles_apply_once(): void
    {
        $doc = Document::make(self::page()->body(Toc::make(), Text::h1('One')));
        $sizeOf = function (?Theme $theme) use ($doc): float {
            $f = self::owner(self::fragments($this->place($doc, theme: $theme))[0]);
            $runs = array_values(array_filter(self::ops($f), static fn (Op $o): bool => $o instanceof TextRun));
            return $runs[0]->size;
        };
        $base = $sizeOf(null);
        $themed = $sizeOf(Theme::default()->withStyle('toc.entry', Style::make(fontSize: Length::em(0.9))));
        self::assertEqualsWithDelta($base * 0.9, $themed, 1e-6);
    }

    public function test_a_toc_without_headings_lays_out_only_its_title(): void
    {
        $doc = Document::make(self::page()->body(Toc::make()->title('Contents'), Text::make('Plain')));
        $f = self::fragments($this->place($doc))[0];
        $texts = array_map(static fn (TextRun $r): string => $r->text, array_values(array_filter(self::ops($f), static fn (Op $o): bool => $o instanceof TextRun)));
        self::assertContains('Contents', $texts);
        self::assertSame([], self::slots($f));
        self::assertNotEmpty(array_filter($this->warnings, static fn (string $w): bool => str_contains($w, 'table of contents has no entries')));
    }
}
