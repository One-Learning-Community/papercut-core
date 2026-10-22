<?php

declare(strict_types=1);

namespace Papercut\Tests\Text;

use Papercut\Span;
use Papercut\Style\{ComputedStyle, Style, Theme};
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
