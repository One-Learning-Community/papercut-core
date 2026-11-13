<?php

declare(strict_types=1);

namespace Papercut\Tests\Text;

use InvalidArgumentException;
use Papercut\{Span, Text};
use Papercut\Decorator\Badge;
use Papercut\Exception\UnknownAttribute;
use Papercut\Layout\Fragment;
use Papercut\Paint\{Link as LinkOp, Rect, TextRun};
use Papercut\Style\{Style, Theme, WhiteSpace};
use Papercut\Value\Edges;
use Papercut\Tests\Support\LayoutHarness;
use Papercut\Value\Color;
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

final class InlineBadgeTest extends TestCase
{
    // The test font is 0.5em per character: a 9pt badge label adds 4.5pt per character to its 12pt of padding.

    /** @return list<object> */
    private static function ops(Text $text, float $width, ?Theme $theme = null): array
    {
        $fragment = (new LayoutHarness($theme))->layout($text, $width)->fragment;
        self::assertInstanceOf(Fragment::class, $fragment);
        return $fragment->ops();
    }

    /**
     * @param list<object> $ops
     * @return list<Rect>
     */
    private static function rects(array $ops): array
    {
        return array_values(array_filter($ops, static fn (object $o): bool => $o instanceof Rect));
    }

    /**
     * @param list<object> $ops
     * @return list<TextRun>
     */
    private static function runs(array $ops): array
    {
        return array_values(array_filter($ops, static fn (object $o): bool => $o instanceof TextRun));
    }

    public function test_badge_span_paints_a_rounded_rect_behind_its_text(): void
    {
        $ops = self::ops(Text::spans(Span::text('Returns '), Span::badge('201', 'tip'), Span::text(' when it works.')), 300);
        $rects = self::rects($ops);
        self::assertCount(1, $rects);
        self::assertSame(8.0, $rects[0]->radius);
        self::assertEquals(Color::named('emerald-100'), $rects[0]->fill);
        self::assertSame(40.0, $rects[0]->x);
        self::assertLessThan(14.0, $rects[0]->height);
        self::assertGreaterThan(0.0, $rects[0]->height);
        $label = array_values(array_filter(self::runs($ops), static fn (TextRun $r): bool => $r->text === '201'));
        self::assertCount(1, $label);
        self::assertSame($rects[0]->x + 6.0, $label[0]->x);
        self::assertEquals(Color::named('emerald-800'), $label[0]->color);
    }

    public function test_badge_padding_counts_in_the_line(): void
    {
        $ops = self::ops(Text::spans(Span::text('Returns '), Span::badge('201', 'tip'), Span::text(' when it works.')), 300);
        $rect = self::rects($ops)[0];
        self::assertSame(25.5, $rect->width);
        $after = array_values(array_filter(self::runs($ops), static fn (TextRun $r): bool => str_contains($r->text, 'when')));
        self::assertCount(1, $after);
        // The run is the space then the words: the space sits right after the pill, and the word one space further.
        self::assertSame($rect->x + $rect->width, $after[0]->x);
        self::assertStringStartsWith(' when', $after[0]->text);
    }

    public function test_a_badge_that_does_not_fit_moves_whole_to_the_next_line(): void
    {
        $ops = self::ops(Text::spans(Span::text('Returns '), Span::badge('201', 'tip')), 60);
        $rect = self::rects($ops)[0];
        self::assertSame(0.0, $rect->x);
        self::assertSame(25.5, $rect->width);
        $runs = self::runs($ops);
        self::assertSame(['Returns', '201'], array_map(static fn (TextRun $r): string => $r->text, $runs));
        self::assertGreaterThan($runs[0]->baseline, $runs[1]->baseline);
        self::assertSame(6.0, $runs[1]->x);
    }

    public function test_a_badge_wider_than_the_line_behaves_like_an_overlong_word(): void
    {
        // 10 characters are 57pt with padding; the line is 40pt, so the label splits character-wise, like a word.
        $ops = self::ops(Text::spans(Span::badge('abcdefghij')), 40);
        $rects = self::rects($ops);
        self::assertGreaterThan(1, count($rects));
        $text = implode('', array_map(static fn (TextRun $r): string => $r->text, self::runs($ops)));
        self::assertSame('abcdefghij', $text);
        foreach ($rects as $rect) {
            self::assertLessThanOrEqual(40.0 + 1e-9, $rect->width);
        }
    }

    public function test_justified_lines_do_not_stretch_inside_a_badge(): void
    {
        $text = Text::spans(Span::text('aa bb '), Span::badge('Strongly agree'), Span::text(' cc dd ee ff gg hh'))->justify();
        $ops = self::ops($text, 130);
        $rect = self::rects($ops)[0];
        // 14 characters at 4.5pt plus 12pt of padding.
        self::assertSame(75.0, $rect->width);
        $label = array_values(array_filter(self::runs($ops), static fn (TextRun $r): bool => $r->text === 'Strongly agree'));
        self::assertCount(1, $label);
        // The words before it were stretched: 'bb' starts further right than unjustified (15).
        $bb = array_values(array_filter(self::runs($ops), static fn (TextRun $r): bool => $r->text === 'bb'));
        self::assertGreaterThan(15.0, $bb[0]->x);
    }

    public function test_a_badge_inside_a_link_has_a_link_area_covering_the_pill(): void
    {
        $badge = new Span('201', Style::none(), 'https://example.test/', false, 'badge badge.tip');
        $ops = self::ops(Text::spans(Span::text('See '), $badge), 300);
        $links = array_values(array_filter($ops, static fn (object $o): bool => $o instanceof LinkOp));
        self::assertCount(1, $links);
        $rect = self::rects($ops)[0];
        self::assertSame($rect->x, $links[0]->x);
        self::assertSame($rect->width, $links[0]->width);
    }

    public function test_outline_variant_draws_a_border_and_no_fill(): void
    {
        $ops = self::ops(Text::spans(Span::badge('Beta', 'note', 'outline')), 300);
        $rects = self::rects($ops);
        self::assertCount(1, $rects);
        self::assertNull($rects[0]->fill);
        self::assertEquals(Color::named('sky-300'), $rects[0]->stroke);
        self::assertSame(0.75, $rects[0]->strokeWidth);
    }

    public function test_theme_override_of_badge_tone_reaches_inline_badges(): void
    {
        $theme = Theme::default()->withStyle('badge.tip', Style::make(background: Color::named('red-100'), padding: \Papercut\Value\Edges::of(pt(0), pt(10))));
        $ops = self::ops(Text::spans(Span::badge('201', 'tip')), 300, $theme);
        $rect = self::rects($ops)[0];
        self::assertEquals(Color::named('red-100'), $rect->fill);
        self::assertSame(33.5, $rect->width);
    }

    public function test_unknown_tone_names_the_missing_theme_style(): void
    {
        $this->expectException(UnknownAttribute::class);
        $this->expectExceptionMessage('badge.zzz');
        self::ops(Text::spans(Span::badge('x', 'zzz')), 300);
    }

    public function test_span_badge_rejects_the_same_tones_as_Badge(): void
    {
        foreach (['', '  ', 'a b', 'x?'] as $tone) {
            $block = null;
            try {
                Badge::make('x')->tone($tone);
            } catch (InvalidArgumentException $e) {
                $block = $e->getMessage();
            }
            self::assertNotNull($block, "Badge accepted '$tone'");
            try {
                Span::badge('x', $tone);
                self::fail("Span::badge accepted '$tone'");
            } catch (InvalidArgumentException $e) {
                self::assertSame($block, $e->getMessage());
            }
        }
        foreach (['', 'Big', 'a?', 'badge', 'tip'] as $variant) {
            $block = null;
            try {
                Badge::make('x')->variant($variant);
            } catch (InvalidArgumentException $e) {
                $block = $e->getMessage();
            }
            self::assertNotNull($block, "Badge accepted variant '$variant'");
            try {
                Span::badge('x', 'neutral', $variant);
                self::fail("Span::badge accepted variant '$variant'");
            } catch (InvalidArgumentException $e) {
                self::assertSame($block, $e->getMessage());
            }
        }
    }

    public function test_span_badge_style_names(): void
    {
        self::assertSame('badge badge.tip', Span::badge('x', 'tip')->styleName);
        self::assertSame('badge badge.neutral badge.outline? badge.wide?', Span::badge('x', variant: 'outline wide')->styleName);
    }

    public function test_highlight_spans_keep_full_line_height_rects(): void
    {
        $ops = self::ops(Text::spans(Span::text('a '), Span::text('mark', Style::make(background: Color::named('amber-100'))), Span::text(' b')), 300);
        $rects = self::rects($ops);
        self::assertCount(1, $rects);
        self::assertSame(14.0, $rects[0]->height);
        self::assertSame(0.0, $rects[0]->y);
    }

    public function test_inline_code_keeps_its_full_line_height_rect(): void
    {
        $rects = self::rects(self::ops(Text::spans(Span::text('a '), Span::code('x')), 300));
        self::assertCount(1, $rects);
        self::assertSame(14.0, $rects[0]->height);
    }

    public function test_a_badge_in_pre_wrap_text_is_still_a_padded_pill(): void
    {
        $text = Text::spans(Span::text('Returns '), Span::badge('201', 'tip'), Span::text(' ok'))->apply(new Style(whiteSpace: WhiteSpace::PreWrap));
        $rects = self::rects(self::ops($text, 300));
        self::assertCount(1, $rects);
        self::assertSame(8.0, $rects[0]->radius);
        self::assertSame(25.5, $rects[0]->width);
    }

    public function test_a_bottom_border_alone_is_not_an_inline_box_and_wraps(): void
    {
        $style = Style::make(borderWidth: Edges::parse('0 0 1pt 0'));
        $text = Text::spans(Span::text('alpha '), Span::text('beta gamma', $style));
        $runs = self::runs(self::ops($text, 60));
        self::assertGreaterThan($runs[0]->baseline, $runs[1]->baseline);
        self::assertSame(['alpha beta', 'gamma'], array_map(static fn (TextRun $r): string => $r->text, $runs));
    }

    public function test_spaces_inside_a_padded_span_still_space_it_from_its_neighbours(): void
    {
        $style = Style::make(padding: Edges::parse('0 4pt'));
        $glued = self::runs(self::ops(Text::spans(Span::text('run'), Span::text('npm', $style), Span::text('now')), 300));
        $spaced = self::runs(self::ops(Text::spans(Span::text('run'), Span::text(' npm ', $style), Span::text('now')), 300));
        $x = static fn (array $runs, string $t): float => array_values(array_filter($runs, static fn (TextRun $r): bool => $r->text === $t))[0]->x;
        // The test font is 0.5em per character: a 10pt space is 5pt.
        self::assertSame($x($glued, 'npm') + 5.0, $x($spaced, 'npm'));
        // The trailing space joins the text after the box: it starts one space later than the glued one.
        self::assertSame($x($glued, 'now') + 5.0, $x($spaced, ' now'));
    }
}
