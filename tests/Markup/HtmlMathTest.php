<?php

declare(strict_types=1);

namespace Papercut\Tests\Markup;

use Papercut\Document;
use Papercut\Equation;
use Papercut\Export\PdfOptions;
use Papercut\Image;
use Papercut\Link;
use Papercut\Markup\Markup;
use Papercut\Markup\MarkupOptions;
use Papercut\Markup\StyleMode;
use Papercut\Math\Render\TextWriter;
use Papercut\Page;
use Papercut\Style\TextAlign;
use Papercut\Tests\Support\FakeBackend;
use Papercut\Tests\Support\TestImages;
use Papercut\Text;
use Papercut\View;
use PHPUnit\Framework\TestCase;

final class HtmlMathTest extends TestCase
{
    private const string PI = '<math><mi>π</mi><msup><mi>r</mi><mn>2</mn></msup></math>';

    /** @return list<\Papercut\Node|\Papercut\Component> */
    private static function blocks(string $html): array
    {
        $v = Markup::html($html);
        return $v instanceof View ? $v->children() : [$v];
    }

    /** @return list<\Papercut\Span> */
    private static function spans(string $html): array
    {
        $first = self::blocks($html)[0];
        self::assertInstanceOf(Text::class, $first);
        return $first->spanList();
    }

    /** @return list<string> the export warnings of the converted markup */
    private static function warnings(string $html): array
    {
        $warnings = [];
        Document::make(Page::a4()->body(Markup::html($html)))->toPdf(new PdfOptions(
            backend: new FakeBackend(),
            onWarning: function (string $m) use (&$warnings): void { $warnings[] = $m; },
        ));
        return $warnings;
    }

    public function test_inline_math_in_paragraph(): void
    {
        $text = self::blocks('<p>Area ' . self::PI . '.</p>')[0];
        self::assertInstanceOf(Text::class, $text);
        $spans = $text->spanList();
        self::assertCount(3, $spans);
        self::assertSame('Area ', $spans[0]->text);
        self::assertNotNull($spans[1]->math);
        self::assertNull($spans[0]->math);
        self::assertSame('.', $spans[2]->text);
    }

    public function test_paragraph_with_only_math_kept(): void
    {
        $blocks = self::blocks('<p>' . self::PI . '</p><p>after</p>');
        self::assertCount(2, $blocks);
        self::assertInstanceOf(Text::class, $blocks[0]);
        self::assertNotNull($blocks[0]->spanList()[0]->math);
    }

    public function test_block_math_splits_paragraph(): void
    {
        $blocks = self::blocks('<p>a <math display="block"><mi>x</mi></math> b</p>');
        self::assertCount(3, $blocks);
        self::assertInstanceOf(Text::class, $blocks[0]);
        self::assertInstanceOf(Equation::class, $blocks[1]);
        self::assertInstanceOf(Text::class, $blocks[2]);
        self::assertSame('a', $blocks[0]->spanList()[0]->text);
        self::assertSame('b', $blocks[2]->spanList()[0]->text);
    }

    public function test_word_prefixed_math(): void
    {
        $spans = self::spans('<p>a <mml:math><mml:mi>x</mml:mi></mml:math> b</p>');
        self::assertCount(3, $spans);
        self::assertNotNull($spans[1]->math);
        self::assertSame([], self::warnings('<p>a <mml:math><mml:mi>x</mml:mi></mml:math> b</p>'));
    }

    public function test_wiris_image_plain_and_safexml(): void
    {
        $plain = '<img class="Wirisformula" src="x.png" data-mathml="&lt;math&gt;&lt;mi&gt;x&lt;/mi&gt;&lt;/math&gt;">';
        $safe = '<img class="foo Wirisformula" src="x.png" data-mathml="«math»«mi»y«/mi»«/math»">';
        $spans = self::spans("<p>a $plain b $safe c</p>");
        self::assertSame(['a ', null, ' b ', null, ' c'], array_map(static fn ($s) => $s->math === null ? $s->text : null, $spans));
        self::assertNotNull($spans[1]->math);
        self::assertNotNull($spans[3]->math);
        self::assertSame('x', TextWriter::write($spans[1]->math));
        self::assertSame('y', TextWriter::write($spans[3]->math));
        self::assertSame([], self::warnings("<p>a $plain b $safe c</p>"));
    }

    public function test_wiris_image_without_mathml_stays_image(): void
    {
        $png = 'x.png';
        $options = new MarkupOptions(images: static fn (string $src): string => TestImages::png());
        foreach (['', ' data-mathml=""'] as $attr) {
            $blocks = ($h = Markup::html('<img class="Wirisformula" src="' . $png . '"' . $attr . '>', $options)) instanceof View ? $h->children() : [$h];
            self::assertCount(1, $blocks);
            self::assertInstanceOf(Image::class, $blocks[0]);
        }
    }

    public function test_safexml_text_runs(): void
    {
        $spans = self::spans('<p>if «math»«mi»a«/mi»«/math» and «math»«mi»b«/mi»«/math» then</p>');
        self::assertCount(5, $spans);
        self::assertSame('if ', $spans[0]->text);
        self::assertNotNull($spans[1]->math);
        self::assertSame(' and ', $spans[2]->text);
        self::assertNotNull($spans[3]->math);
        self::assertSame(' then', $spans[4]->text);
    }

    public function test_french_quotes_untouched(): void
    {
        $spans = self::spans('<p>Il dit «bonjour» à «mathieu».</p>');
        self::assertCount(1, $spans);
        self::assertSame('Il dit «bonjour» à «mathieu».', $spans[0]->text);
    }

    public function test_mtable_note_reported_once(): void
    {
        $table = '<mtable rowlines="solid"><mtr><mtd><mn>1</mn></mtd></mtr></mtable>';
        $block = self::warnings('<math display="block">' . $table . '</math>');
        $inline = self::warnings('<p>x <math>' . $table . '</math> y</p>');
        self::assertCount(1, $block, implode("\n", $block));
        self::assertCount(1, $inline, implode("\n", $inline));
    }

    public function test_no_unknown_tag_note_for_math(): void
    {
        self::assertSame([], self::warnings('<p>a ' . self::PI . ' b</p><math display="block"><mi>x</mi></math>'));
    }

    public function test_safexml_inside_code_and_pre_is_literal(): void
    {
        $run = '«math»«mi»q«/mi»«/math»';
        foreach (['<p><code>a ' . $run . ' b</code></p>', '<p><kbd>' . $run . '</kbd></p>', '<pre>a ' . $run . ' b</pre>'] as $html) {
            foreach (self::spans($html) as $span) {
                self::assertNull($span->math, $html);
            }
            self::assertStringContainsString('«math»', implode('', array_map(static fn ($s) => $s->text, self::spans($html))));
        }
    }

    public function test_math_element_inside_pre(): void
    {
        $spans = self::spans('<pre>a <math><mi>q</mi></math> b</pre>');
        self::assertCount(3, $spans);
        self::assertSame('a ', $spans[0]->text);
        self::assertSame('q', TextWriter::write($spans[1]->math ?? self::fail("no math")));
        self::assertSame(' b', $spans[2]->text);
    }

    public function test_math_in_a_link_carries_the_href(): void
    {
        $spans = self::spans('<p><a href="https://example.com/x">the ' . self::PI . ' formula</a></p>');
        self::assertSame('https://example.com/x', $spans[1]->href);
        self::assertNotNull($spans[1]->math);

        $wiris = '<img class="Wirisformula" src="x.png" data-mathml="&lt;math&gt;&lt;mi&gt;x&lt;/mi&gt;&lt;/math&gt;">';
        $spans = self::spans('<p><a href="https://example.com/y">' . $wiris . '</a></p>');
        self::assertCount(1, $spans);
        self::assertNotNull($spans[0]->math);
        self::assertSame('https://example.com/y', $spans[0]->href);

        $spans = self::spans('<p><a href="https://example.com/z">«math»«mi»s«/mi»«/math»</a></p>');
        self::assertSame('https://example.com/z', $spans[0]->href);
    }

    public function test_math_takes_the_surrounding_style(): void
    {
        $v = Markup::html('<p><span style="color:#c00">r ' . self::PI . '</span></p>', new MarkupOptions(styles: StyleMode::Original));
        $text = $v instanceof View ? $v->children()[0] : $v;
        self::assertInstanceOf(Text::class, $text);
        $spans = $text->spanList();
        self::assertNotNull($spans[1]->math);
        self::assertEquals($spans[0]->style->color, $spans[1]->style->color);
        self::assertNotNull($spans[1]->style->color);
    }

    public function test_math_keeps_only_colour_size_background_and_href(): void
    {
        $html = '<p><a href="https://e.test/a"><b><i><u><s><span style="color:#c00;font-size:20px;background-color:#eee;letter-spacing:2px">'
            . self::PI . '</span></s></u></i></b></a></p>';
        $v = Markup::html($html, new MarkupOptions(styles: StyleMode::Original));
        $text = $v instanceof View ? $v->children()[0] : $v;
        self::assertInstanceOf(Text::class, $text);
        $span = $text->spanList()[0];
        self::assertNotNull($span->math);
        self::assertSame('https://e.test/a', $span->href);
        $st = $span->style;
        self::assertNotNull($st->color);
        self::assertNotNull($st->fontSize);
        self::assertNotNull($st->background);
        self::assertNull($st->underline);
        self::assertNull($st->strike);
        self::assertNull($st->italic);
        self::assertNull($st->fontWeight);
        self::assertNull($st->letterSpacing);
    }

    public function test_display_formula_takes_the_blocks_text_align_in_original_mode_only(): void
    {
        $html = '<p style="text-align:center"><math display="block"><mi>x</mi></math></p>';
        $original = Markup::html($html, new MarkupOptions(styles: StyleMode::Original));
        $equation = $original instanceof View ? $original->children()[0] : $original;
        self::assertInstanceOf(Equation::class, $equation);
        self::assertSame(TextAlign::Center, $equation->style()->textAlign);

        $theme = Markup::html($html);
        $equation = $theme instanceof View ? $theme->children()[0] : $theme;
        self::assertInstanceOf(Equation::class, $equation);
        self::assertNull($equation->style()->textAlign);
    }

    public function test_display_formula_inside_a_link_keeps_the_link(): void
    {
        foreach ([
            '<p><a href="https://e.test/f"><math display="block"><mi>x</mi></math></a></p>',
            '<p><a href="https://e.test/f"><img class="Wirisformula" data-mathml="&lt;math display=&quot;block&quot;&gt;&lt;mi&gt;x&lt;/mi&gt;&lt;/math&gt;"></a></p>',
        ] as $html) {
            $v = Markup::html($html);
            $first = $v instanceof View ? $v->children()[0] : $v;
            self::assertInstanceOf(Link::class, $first, $html);
            self::assertSame('https://e.test/f', $first->uri());
            self::assertInstanceOf(Equation::class, $first->children()[0]);
        }
    }

    public function test_wiris_with_non_markup_data_falls_back_to_the_image(): void
    {
        $options = new MarkupOptions(images: static fn (string $src): string => TestImages::png());
        $h = Markup::html('<img class="Wirisformula" src="f.png" alt="x squared" data-mathml="not math">', $options);
        $blocks = $h instanceof View ? $h->children() : [$h];
        self::assertInstanceOf(Image::class, $blocks[0]);
        $spans = self::spans('<p><img class="Wirisformula" src="missing.png" alt="x squared" data-mathml="not math"></p>');
        self::assertStringContainsString('x squared', implode('', array_map(static fn ($s) => $s->text, $spans)));
    }

    public function test_wide_inline_formula_in_a_table_cell_overflows_instead_of_throwing(): void
    {
        set_error_handler(static function (int $no, string $msg, string $file, int $line): never {
            throw new \ErrorException($msg, 0, $no, $file, $line);
        });
        try {
            $wide = '<math><mrow>' . str_repeat('<mi>x</mi><mo>+</mo>', 60) . '<mi>y</mi></mrow></math>';
            $unclosed = '<math><mrow>' . str_repeat('<mi>x</mi><mo>+</mo>', 60) . '<mi>y</mi>';
            foreach (['closed' => $wide, 'unclosed' => $unclosed] as $name => $math) {
                $notes = [];
                $pdf = Document::make(Page::a4()->body(Markup::html('<table><tr><td>a ' . $math . '</td></tr></table>')))
                    ->toPdf(new PdfOptions(backend: new FakeBackend(), onWarning: static function (string $m) use (&$notes): void {
                        $notes[] = $m;
                    }));
                self::assertNotSame('', $pdf->bytes());
                $wider = array_values(array_filter($notes, static fn (string $n): bool => str_contains($n, 'table content wider than its box')));
                self::assertCount(1, $wider, $name . ': ' . implode("\n", $notes));
            }
        } finally {
            restore_error_handler();
        }
    }
}
