<?php

declare(strict_types=1);

namespace Papercut\Tests\Text;

use Papercut\Span;
use Papercut\Style\{ComputedStyle, Style, Theme, WhiteSpace};
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

    /** @return list<Line> */
    private function preLines(string $text, float $width): array
    {
        $style = ComputedStyle::root(Theme::default())->child(Style::make(whiteSpace: WhiteSpace::PreWrap));
        $items = (new Itemizer(FontRegistry::withDefaults(new FakeBackend())))->itemize([Span::text($text)], $style);
        return LineBreaker::break($items, $width);
    }

    /**
     * @param list<Line> $lines
     * @return list<string>
     */
    private function preTexts(array $lines): array
    {
        return array_map(fn (Line $l) => implode('', array_map(fn ($i) => $i->kind === 'tab' ? '→' : $i->text, $l->items)), $lines);
    }

    /**
     * x of each item of a line.
     *
     * @return list<float>
     */
    private static function xs(Line $line): array
    {
        $x = 0.0;
        $out = [];
        foreach ($line->items as $item) {
            $out[] = $x;
            $x += $item->width;
        }
        return $out;
    }

    public function test_pre_wrap_keeps_runs_of_spaces(): void
    {
        $lines = $this->preLines('a   b', 200);
        self::assertSame(['a   b'], $this->preTexts($lines));
        self::assertSame(25.0, $lines[0]->width);
    }

    public function test_pre_wrap_keeps_leading_spaces_after_break(): void
    {
        $lines = $this->preLines("x\n    y", 200);
        self::assertSame(['x', '    y'], $this->preTexts($lines));
        self::assertSame(20.0, self::xs($lines[1])[4]); // y after four 5pt spaces
        self::assertTrue($lines[0]->endsWithBreak);
    }

    public function test_pre_wrap_blank_lines_survive(): void
    {
        self::assertSame(['a', '', 'b'], $this->preTexts($this->preLines("a\n\nb", 200)));
    }

    public function test_pre_wrap_tab_advances_to_next_stop(): void
    {
        // a space is 5pt, so stops sit every 20pt
        $ab = $this->preLines("ab\tc", 200)[0];
        self::assertSame(20.0, self::xs($ab)[2]); // ab = 10, tab to 20, c at 20
        $abcd = $this->preLines("abcd\te", 200)[0];
        self::assertSame(40.0, self::xs($abcd)[2]); // already on a stop: a full stop further
        self::assertSame(45.0, $abcd->width);
    }

    public function test_pre_wrap_leading_tab_and_consecutive_tabs(): void
    {
        $lines = $this->preLines("\t\tx", 200);
        self::assertSame([0.0, 20.0, 40.0], self::xs($lines[0]));
        $lines = $this->preLines("a\t\tx", 200);
        self::assertSame(40.0, self::xs($lines[0])[3]);
    }

    public function test_pre_wrap_long_line_wraps(): void
    {
        $lines = $this->preLines(str_repeat('x', 200), 100);
        self::assertGreaterThan(1, count($lines));
        foreach ($lines as $line) {
            self::assertLessThanOrEqual(100.0 + 1e-6, $line->width);
        }
        self::assertSame(str_repeat('x', 200), implode('', $this->preTexts($lines)));
    }

    public function test_pre_wrap_long_line_with_spaces_wraps_at_spaces(): void
    {
        $lines = $this->preLines(str_repeat('word ', 20), 100);
        foreach ($lines as $line) {
            self::assertLessThanOrEqual(100.0 + 1e-6, $line->width);
        }
        self::assertSame('word word word word', $this->preTexts($lines)[0]); // 19 chars, 95pt
    }

    public function test_pre_wrap_soft_wrap_drops_the_wrapping_space(): void
    {
        // each char 5pt: "aaa" 15, two spaces 10, "bbb" 15; box 30 -> bbb does not fit after the spaces
        $lines = $this->preLines('aaa  bbb', 30);
        self::assertSame(['aaa', 'bbb'], $this->preTexts($lines));
        self::assertSame(15.0, $lines[0]->width);
        self::assertSame(0.0, self::xs($lines[1])[0]);
    }

    public function test_pre_wrap_spaces_after_a_hard_break_are_kept_even_when_the_previous_line_wrapped(): void
    {
        $lines = $this->preLines("aaa bbb\n  ccc", 30);
        self::assertSame(['aaa', 'bbb', '  ccc'], $this->preTexts($lines));
    }

    public function test_pre_wrap_tab_dropped_at_a_soft_wrap(): void
    {
        $lines = $this->preLines("aaaaa\tbbbbb", 40);
        self::assertSame(['aaaaa', 'bbbbb'], $this->preTexts($lines));
    }

    public function test_normal_text_unchanged_by_the_gap_rework(): void
    {
        self::assertSame(['aaa bbb', 'ccc'], $this->texts($this->lines("aaa   bbb\n ccc", 35)));
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
