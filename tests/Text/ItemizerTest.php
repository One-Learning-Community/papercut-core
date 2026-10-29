<?php

declare(strict_types=1);

namespace Papercut\Tests\Text;

use Papercut\Span;
use Papercut\Style\{ComputedStyle, Style, Theme, WhiteSpace};
use Papercut\Tests\Support\FakeBackend;
use Papercut\Text\{FontRegistry, Itemizer};
use PHPUnit\Framework\TestCase;

final class ItemizerTest extends TestCase
{
    private function itemizer(?\Closure $warn = null): Itemizer
    {
        return new Itemizer(FontRegistry::withDefaults(new FakeBackend()), $warn);
    }

    private function root(): ComputedStyle
    {
        return ComputedStyle::root(Theme::default());
    }

    public function test_collapses_blade_style_whitespace(): void
    {
        $items = $this->itemizer()->itemize([Span::text("\n      Hello\n   world  ")], $this->root());
        self::assertSame(['word:Hello', 'space: ', 'word:world'], array_map(fn ($i) => $i->kind . ':' . $i->text, $items));
    }

    public function test_whitespace_collapses_across_spans_and_widths_use_font(): void
    {
        $items = $this->itemizer()->itemize([Span::text('a '), Span::text(' b', Style::make(fontWeight: 700))], $this->root());
        self::assertSame(['word:a', 'space: ', 'word:b'], array_map(fn ($i) => $i->kind . ':' . $i->text, $items));
        self::assertSame(5.0, $items[0]->width); // FakeFont: 0.5 * 10pt per char
        self::assertSame(700, $items[2]->style->fontWeight);
    }

    private function preWrap(): ComputedStyle
    {
        return $this->root()->child(Style::make(whiteSpace: WhiteSpace::PreWrap));
    }

    /**
     * @param list<\Papercut\Text\InlineItem> $items
     * @return list<string>
     */
    private static function kinds(array $items): array
    {
        return array_map(fn ($i) => $i->kind . ':' . str_replace(["\t", "\n"], ['\\t', '\\n'], $i->text), $items);
    }

    public function test_white_space_is_inherited_and_defaults_to_normal(): void
    {
        self::assertSame(WhiteSpace::Normal, $this->root()->whiteSpace);
        $pre = $this->preWrap();
        self::assertSame(WhiteSpace::PreWrap, $pre->whiteSpace);
        self::assertSame(WhiteSpace::PreWrap, $pre->child(Style::make())->whiteSpace);
        self::assertSame(WhiteSpace::Normal, $pre->child(Style::make(whiteSpace: WhiteSpace::Normal))->whiteSpace);
    }

    public function test_pre_wrap_keeps_runs_of_spaces(): void
    {
        $items = $this->itemizer()->itemize([Span::text('a   b')], $this->preWrap());
        self::assertSame(['word:a', 'space: ', 'space: ', 'space: ', 'word:b'], self::kinds($items));
    }

    public function test_pre_wrap_keeps_leading_and_trailing_spaces(): void
    {
        $items = $this->itemizer()->itemize([Span::text('  a ')], $this->preWrap());
        self::assertSame(['space: ', 'space: ', 'word:a', 'space: '], self::kinds($items));
    }

    public function test_pre_wrap_newlines_are_breaks_and_tabs_are_tab_items(): void
    {
        $items = $this->itemizer()->itemize([Span::text("a\r\nb\rc\n\n\td")], $this->preWrap());
        self::assertSame(['word:a', 'break:', 'word:b', 'break:', 'word:c', 'break:', 'break:', 'tab:\t', 'word:d'], self::kinds($items));
        // A tab item carries the stop pitch: four space advances (FakeFont: 5pt per char at 10pt).
        self::assertSame(20.0, $items[7]->width);
    }

    public function test_mixed_span_keeps_only_its_own_spaces(): void
    {
        $items = $this->itemizer()->itemize([
            Span::text('x   '),
            Span::text("a  b\nc", Style::make(whiteSpace: WhiteSpace::PreWrap)),
            Span::text('   y'),
        ], $this->root());
        self::assertSame(
            ['word:x', 'space: ', 'word:a', 'space: ', 'space: ', 'word:b', 'break:', 'word:c', 'space: ', 'word:y'],
            self::kinds($items),
        );
    }

    public function test_an_empty_pre_wrap_span_between_normal_spans_adds_no_second_space(): void
    {
        $items = $this->itemizer()->itemize([
            Span::text('a '),
            Span::text('', Style::make(whiteSpace: WhiteSpace::PreWrap)),
            Span::text(' b'),
        ], $this->root());

        self::assertSame(['word:a', 'space: ', 'word:b'], self::kinds($items));
    }

    public function test_break_opportunities(): void
    {
        $items = $this->itemizer()->itemize([Span::text('well-known and/or 漢字')], $this->root());
        $words = array_values(array_filter($items, fn ($i) => $i->kind === 'word'));
        self::assertSame('well-', $words[0]->text);
        self::assertSame('known', $words[1]->text);
        self::assertSame('and/', $words[2]->text);
        self::assertTrue($words[2]->breakAfter);
        self::assertTrue($words[0]->breakAfter);
        self::assertSame('漢', $words[count($words) - 2]->text);
        self::assertTrue($words[count($words) - 2]->breakAfter);
    }

    public function test_breaks_drop_adjacent_spaces(): void
    {
        $items = $this->itemizer()->itemize([Span::text('a '), Span::break(), Span::text(' b')], $this->root());
        self::assertSame(['word', 'break', 'word'], array_map(fn ($i) => $i->kind, $items));
    }

    public function test_missing_glyph_warns_once(): void
    {
        $warnings = [];
        $this->itemizer(function (string $w) use (&$warnings) { $warnings[] = $w; })
            ->itemize([Span::text('ok 漢 漢')], $this->root());
        self::assertCount(1, $warnings);
        self::assertStringContainsString('漢', $warnings[0]);
    }

    public function test_empty_input(): void
    {
        self::assertSame([], $this->itemizer()->itemize([Span::text("  \n ")], $this->root()));
    }
}
