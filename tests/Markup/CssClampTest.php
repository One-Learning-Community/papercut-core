<?php

declare(strict_types=1);

namespace Papercut\Tests\Markup;

use Papercut\{Annotated, Document, Node, Page, View};
use Papercut\Export\{FidelityLevel, HtmlOptions, PdfOptions};
use Papercut\Markup\{InlineCss, Markup, MarkupOptions, StyleMode};
use Papercut\Tests\Support\{PdfInspector, TestImages};
use function Papercut\px;
use Papercut\Value\Length;
use PHPUnit\Framework\TestCase;

/** Pasted CSS is untrusted: sizes are clamped, heights never cut content off, columns never overflow. */
final class CssClampTest extends TestCase
{
    protected function setUp(): void
    {
        set_error_handler(static function (int $no, string $msg, string $file, int $line): never {
            throw new \ErrorException($msg, 0, $no, $file, $line);
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
    }

    private static function original(string $html): Node
    {
        return Markup::html($html, new MarkupOptions(styles: StyleMode::Original));
    }

    /** @return array{string, list<string>} the PDF bytes and its warnings */
    private static function pdf(string $html): array
    {
        $messages = [];
        $bytes = Document::make(Page::a4()->body(self::original($html)))->toPdf(new PdfOptions(
            onWarning: function (string $m) use (&$messages): void {
                $messages[] = $m;
            },
            cacheDir: false,
        ))->bytes();
        return [$bytes, $messages];
    }

    /** @return list<string> the approximated and dropped markup notes under $v */
    private static function notes(Node $v): array
    {
        $out = $v instanceof Annotated ? array_map(static fn ($n): string => $n->feature, $v->notes()) : [];
        foreach ($v instanceof View ? $v->children() : [] as $child) {
            if ($child instanceof Node) {
                array_push($out, ...self::notes($child));
            }
        }
        return $out;
    }

    public function test_font_size_is_clamped_to_400pt_with_one_note(): void
    {
        $r = InlineCss::parse('font-size:99999999999px', inline: true);
        self::assertSame(400.0, $r['style']->fontSize?->points);
        self::assertSame([[FidelityLevel::Approximated, 'markup CSS "font-size:99999999999px" (limited to 400pt)']], $r['notes']);
        [$bytes, $messages] = self::pdf('<p>a <span style="font-size:99999999999px">b</span></p>');
        self::assertStringStartsWith('%PDF', $bytes);
        self::assertCount(1, array_filter($messages, static fn (string $m): bool => str_contains($m, 'limited to 400pt')));
    }

    public function test_tiny_font_size_is_raised_to_1pt(): void
    {
        $r = InlineCss::parse('font-size:0.01px', inline: true);
        self::assertSame(1.0, $r['style']->fontSize?->points);
        self::assertCount(1, $r['notes']);
    }

    public function test_padding_and_margin_are_clamped_to_2000pt(): void
    {
        $r = InlineCss::parse('padding:99999999px', inline: false);
        self::assertSame(2000.0, $r['style']->padding?->top->points);
        self::assertSame([[FidelityLevel::Approximated, 'markup CSS "padding:99999999px" (limited to 2000pt)']], $r['notes']);
        $r = InlineCss::parse('margin:-99999999px 0 0 0', inline: false);
        self::assertSame(-2000.0, $r['style']->margin?->top->points);
        self::assertCount(1, $r['notes']);
        $r = InlineCss::parse('width:99999999px', inline: false);
        self::assertSame(2000.0, ($r['style']->width instanceof Length ? $r['style']->width->points : null));
        [$bytes, $messages] = self::pdf('<div style="padding:99999999px"><p>x</p></div>');
        self::assertStringStartsWith('%PDF', $bytes);
        self::assertCount(1, array_filter($messages, static fn (string $m): bool => str_contains($m, 'padding:99999999px')), implode("\n", $messages));
    }

    public function test_non_finite_values_are_dropped_with_a_note(): void
    {
        foreach (['width:1e999px', 'font-size:nanpx', 'padding:1e999px', 'width:-1e999px'] as $css) {
            $r = InlineCss::parse($css, inline: false);
            self::assertSame([$css], $r['dropped'], $css);
            [$bytes, $messages] = self::pdf('<div style="' . $css . '"><p>hi</p></div>');
            self::assertStringStartsWith('%PDF', $bytes);
            self::assertCount(1, array_filter($messages, static fn (string $m): bool => str_contains($m, '"' . $css . '"')), $css . ': ' . implode("\n", $messages));
        }
    }

    public function test_a_fixed_height_too_small_for_its_content_keeps_the_content(): void
    {
        [$bytes, $messages] = self::pdf('<div style="height:20px">alpha<br>bravo<br>charlie<br>delta</div>');
        $text = PdfInspector::text($bytes);
        foreach (['alpha', 'bravo', 'charlie', 'delta'] as $word) {
            self::assertStringContainsString($word, $text);
        }
        self::assertCount(1, array_filter($messages, static fn (string $m): bool => str_contains($m, 'height:20px')), implode("\n", $messages));
        $v = self::original('<div style="height:20px">a</div>');
        self::assertSame(['markup CSS "height:20px" (applied as a minimum height; content is never cut off)'], self::notes($v));
    }

    public function test_max_height_never_cuts_content_off(): void
    {
        [$bytes, $messages] = self::pdf('<p style="max-height:10px">' . str_repeat('lorem ipsum ', 200) . 'ENDWORD</p>');
        self::assertStringContainsString('ENDWORD', PdfInspector::text($bytes));
        self::assertCount(1, array_filter($messages, static fn (string $m): bool => str_contains($m, 'max-height:10px')));
    }

    public function test_an_image_keeps_its_fixed_height(): void
    {
        $v = Markup::html('<img src="a.png" style="height:70px">', new MarkupOptions(
            styles: StyleMode::Original,
            images: static fn (string $src): string => TestImages::png(60, 140),
        ));
        self::assertInstanceOf(View::class, $v);
        $image = $v->children()[0];
        self::assertInstanceOf(\Papercut\Image::class, $image);
        self::assertEquals(px(70), $image->style()->height);
        self::assertSame([], array_filter(self::notes($v), static fn (string $n): bool => str_contains($n, 'minimum height')));
        $r = InlineCss::parse('height:70px', inline: false);
        self::assertSame(52.5, $r['style']->height instanceof Length ? $r['style']->height->points : null);
        self::assertSame([], $r['notes']);
    }

    public function test_too_many_columns_are_reduced_with_one_note(): void
    {
        $paras = implode('', array_map(static fn (int $i): string => "<p>para$i text</p>", range(1, 12)));
        [$bytes, $messages] = self::pdf('<div style="column-count:20">' . $paras . '</div>');
        $text = PdfInspector::text($bytes);
        self::assertStringContainsString('para1 ', $text . ' ');
        self::assertStringContainsString('para12', $text);
        $reduced = array_values(array_filter($messages, static fn (string $m): bool => str_contains($m, 'column-count (reduced')));
        self::assertCount(1, $reduced, implode("\n", $messages));
        [$bytes] = self::pdf('<div style="column-count:99999; column-gap:99999px"><p>x</p></div>');
        self::assertStringStartsWith('%PDF', $bytes);
    }

    public function test_columns_that_fit_are_untouched_and_silent(): void
    {
        [, $messages] = self::pdf('<div style="column-count:2"><p>one</p><p>two</p></div>');
        self::assertSame([], $messages);
    }

    public function test_theme_mode_ignores_pasted_css(): void
    {
        $v = Markup::html('<div style="height:20px;font-size:99999px"><p>a</p></div>');
        self::assertSame([], self::notes($v));
    }

    /** @return list<string> the HTML export's warnings */
    private static function htmlMessages(string $html): array
    {
        $messages = [];
        Document::make(Page::a4()->body(self::original($html)))->toHtml(new HtmlOptions(onWarning: function (string $m) use (&$messages): void {
            $messages[] = $m;
        }));
        return $messages;
    }

    /** @param list<string> $messages */
    private static function matching(array $messages, string $needle): int
    {
        return count(array_filter($messages, static fn (string $m): bool => str_contains($m, $needle)));
    }

    public function test_column_count_is_noted_once_in_pdf_and_html_even_when_absurd(): void
    {
        foreach (['20', '99999'] as $n) {
            $html = '<div style="column-count:' . $n . '"><p>a</p><p>b</p></div>';
            self::assertSame(1, self::matching(self::pdf($html)[1], 'column'), 'pdf ' . $n);
            $h = self::htmlMessages($html);
            self::assertSame(1, self::matching($h, 'markup column-count (reduced'), 'html ' . $n . implode("\n", $h));
        }
        $bytes = Document::make(Page::a4()->body(self::original('<div style="column-count:20"><p>a</p></div>')))->toHtml(new HtmlOptions())->bytes();
        self::assertStringNotContainsString('column-count:20', $bytes);
    }

    /** @return iterable<string, array{string}> */
    public static function negativeMargins(): iterable
    {
        yield 'all sides' => ['<div style="margin:-20px">a</div>'];
        yield 'top only' => ['<div style="margin:-30px 0 0 0">a</div>'];
        yield 'after a paragraph' => ['<p>a</p><div style="margin:-30px">b</div>'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('negativeMargins')]
    public function test_negative_margins_never_throw(string $html): void
    {
        [$bytes, $messages] = self::pdf($html);
        self::assertStringStartsWith('%PDF', $bytes);
        self::assertSame([], $messages);
        self::htmlMessages($html);
    }

    public function test_a_percent_width_cell_is_fitted_in_pdf_and_html(): void
    {
        foreach (['100%', '150%', '99999%'] as $w) {
            $html = '<table><tr><td style="width:' . $w . '">a</td><td>b</td></tr></table>';
            [$bytes, $messages] = self::pdf($html);
            self::assertStringStartsWith('%PDF', $bytes);
            self::assertSame(1, self::matching($messages, 'markup column widths (wider than the page; fitted)'), $w . ': ' . implode("\n", $messages));
            self::assertSame(1, self::matching(self::htmlMessages($html), 'markup column widths (wider than the page; fitted)'), $w);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function percentTables(): iterable
    {
        $row = '<tr><td style="width:100%">a</td><td>b</td></tr>';
        yield 'in columns' => ['<div style="column-count:3"><table>' . $row . '</table></div>'];
        yield 'table 200px' => ['<table style="width:200px">' . $row . '</table>'];
        yield 'table 120px' => ['<table style="width:120px">' . $row . '</table>'];
        yield '14 autos' => ['<table><tr><td style="width:100%">a</td>' . str_repeat('<td>b</td>', 14) . '</tr></table>'];
        yield '20 autos' => ['<table><tr><td style="width:100%">a</td>' . str_repeat('<td>b</td>', 20) . '</tr></table>'];
        yield 'next to 50px' => ['<table><tr><td style="width:100%">a</td><td style="width:50px">b</td></tr></table>'];
        yield 'next to 200px' => ['<table><tr><td style="width:100%">a</td><td style="width:200px">b</td></tr></table>'];
        yield 'in 40% div' => ['<div style="width:40%"><table>' . $row . '</table></div>'];
        yield 'over 100%' => ['<table><tr><td style="width:150%">a</td><td>b</td></tr></table>'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('percentTables')]
    public function test_percent_tables_are_fitted_at_layout_with_one_note(string $html): void
    {
        [$bytes, $messages] = self::pdf($html);
        self::assertStringStartsWith('%PDF', $bytes);
        self::assertSame(1, self::matching($messages, 'markup column widths (wider than the page; fitted)'), implode("\n", $messages));
        self::assertSame(0, self::matching($messages, 'Table columns need'));
        self::assertSame(1, self::matching(self::htmlMessages($html), 'markup column widths (wider than the page; fitted)'));
    }

    public function test_percent_sums_that_fit_are_laid_out_as_written_without_a_note(): void
    {
        foreach (['<td style="width:95%">a</td><td>b</td>', '<td style="width:50%">a</td><td>b</td><td style="width:45%">c</td>'] as $row) {
            $html = '<table><tr>' . $row . '</tr></table>';
            self::assertSame([], self::pdf($html)[1], $row);
            self::assertSame(0, self::matching(self::htmlMessages($html), 'fitted'), $row);
        }
    }

    public function test_a_fixed_height_box_still_contains_its_overflow_despite_a_negative_margin(): void
    {
        $words = implode(' ', array_fill(0, 400, 'word'));
        $doc = fn (\Papercut\OverflowMode $m) => Document::make(Page::a4()->body(
            \Papercut\Column::make(\Papercut\Text::make('first')->margin(\Papercut\pt(-1), \Papercut\pt(0), \Papercut\pt(0), \Papercut\pt(0)), \Papercut\Text::make($words))->height(\Papercut\pt(50)),
        ))->toPdf(new PdfOptions(overflow: $m, onWarning: static function (string $m): void {}, cacheDir: false));
        self::assertStringStartsWith('%PDF', $doc(\Papercut\OverflowMode::Clip)->bytes());
        $this->expectException(\Papercut\Exception\LayoutOverflow::class);
        $doc(\Papercut\OverflowMode::Throw);
    }

    public function test_a_narrow_column_next_to_an_overflowing_one_keeps_its_width_and_gets_no_fitted_note(): void
    {
        $long = '<td>' . str_repeat('W', 120) . '</td>';
        $word = str_repeat('<td>Supercalifragilisticexpialidocious</td>', 12);
        foreach (['<table><tr><td style="width:20px">x</td>' . $long . '</tr></table>', '<table><tr><td style="width:5%">x</td>' . $word . '</tr></table>'] as $html) {
            [, $messages] = self::pdf($html);
            self::assertSame(0, self::matching($messages, 'fitted'), implode("\n", $messages));
        }
    }
}
