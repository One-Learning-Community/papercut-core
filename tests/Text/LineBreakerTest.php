<?php

declare(strict_types=1);

namespace Papercut\Tests\Text;

use Papercut\Span;
use Papercut\Style\{ComputedStyle, Style, Theme};
use Papercut\Tests\Support\{FakeBackend, FakeFont};
use Papercut\Text\{FontRegistry, Itemizer, Line, LineBreaker};
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

final class LineBreakerTest extends TestCase
{
    /** @return list<Line> */
    private function lines(string $text, float $width): array
    {
        $items = (new Itemizer(FontRegistry::withDefaults(new FakeBackend())))->itemize([Span::text($text)], ComputedStyle::root(Theme::default()));
        return LineBreaker::break($items, $width);
    }

    /**
     * @param list<Line> $lines
     * @return list<string>
     */
    private function texts(array $lines): array
    {
        return array_map(fn (Line $l) => implode('', array_map(fn ($i) => $i->text, $l->items)), $lines);
    }

    public function test_greedy_wrap(): void
    {
        // each char = 5pt at 10pt FakeFont; "aaa bbb ccc" -> words 15pt, space 5pt
        self::assertSame(['aaa bbb', 'ccc'], $this->texts($this->lines('aaa bbb ccc', 35)));
        self::assertSame(['aaa', 'bbb', 'ccc'], $this->texts($this->lines('aaa bbb ccc', 34)));
    }

    public function test_line_width_excludes_trailing_space(): void
    {
        $lines = $this->lines('aaa bbb ccc', 35);
        self::assertSame(35.0, $lines[0]->width);
        self::assertFalse($lines[0]->isLast);
        self::assertTrue($lines[1]->isLast);
    }

    public function test_overlong_word_breaks_by_character(): void
    {
        self::assertSame(['abcd', 'efgh', 'ij'], $this->texts($this->lines('abcdefghij', 20)));
    }

    public function test_tiny_width_still_progresses(): void
    {
        self::assertCount(3, $this->lines('abc', 0));
    }

    public function test_hyphen_break_opportunity(): void
    {
        self::assertSame(['well-', 'known'], $this->texts($this->lines('well-known', 30)));
    }

    public function test_empty(): void
    {
        self::assertSame([], $this->lines('', 100));
    }

    // --- Task 13 ---------------------------------------------------------

    public function test_spaces_with_non_positive_width_are_kept(): void
    {
        $style = ComputedStyle::root(Theme::default())->child(new Style(letterSpacing: pt(-6)));
        $items = (new Itemizer(FontRegistry::withDefaults(new FakeBackend())))->itemize([Span::text('ab cd ef')], $style);
        self::assertLessThan(0.0, $items[1]->width);
        $lines = LineBreaker::break($items, 100);
        self::assertSame(['ab cd ef'], $this->texts($lines));
    }

    public function test_overlong_word_splitting_is_linear(): void
    {
        // pseudo-random letters, so no prefix repeats and the font cache cannot hide re-measuring
        $word = '';
        $x = 42;
        for ($i = 0; $i < 2000; $i++) {
            $x = ($x * 1103515245 + 12345) % 2147483648;
            $word .= chr(97 + ($x >> 16) % 26);
        }
        $items = (new Itemizer(FontRegistry::withDefaults(new FakeBackend())))->itemize([Span::text($word)], ComputedStyle::root(Theme::default()));
        $font = $items[0]->font->inner();
        self::assertInstanceOf(FakeFont::class, $font);
        $font->advanceCalls = 0;
        $font->advancedChars = 0;

        $lines = LineBreaker::break($items, 95);

        self::assertSame($word, implode('', $this->texts($lines)));
        self::assertLessThanOrEqual(2 * 2000, $font->advanceCalls);
        self::assertLessThanOrEqual(2 * 2000, $font->advancedChars);
        foreach ($lines as $line) {
            self::assertLessThanOrEqual(95.0 + 1e-9, $line->width);
            self::assertEqualsWithDelta(5.0 * mb_strlen($line->items[0]->text), $line->items[0]->width, 1e-9);
        }
    }

    // --- Final review ---

    public function test_zero_width_spaces_are_kept(): void
    {
        $style = ComputedStyle::root(Theme::default())->child(new Style(letterSpacing: pt(-5)));
        $items = (new Itemizer(FontRegistry::withDefaults(new FakeBackend())))->itemize([Span::text('ab cd')], $style);
        self::assertSame('space', $items[1]->kind);
        self::assertSame(0.0, $items[1]->width);
        self::assertSame(['ab cd'], $this->texts(LineBreaker::break($items, 100)));
    }
}
