<?php

declare(strict_types=1);

namespace Papercut\Tests\Contract;

use Papercut\Backend\Backend;
use Papercut\Paint\{Clip, DocumentMeta, DrawImage, Line, Link, Op, Opacity, PageDisplayList, Rect, TextRun};
use Papercut\Tests\Support\{FakeFont, PdfInspector, TestImages};
use Papercut\Text\FontRegistry;
use Papercut\Value\Color;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Spec section 9 contract every Backend implementation must satisfy.
 * Subclasses only supply backend(); it must return a fresh instance per call.
 */
abstract class BackendContractTestCase extends TestCase
{
    private Backend $backend;
    private FontRegistry $fonts;

    abstract protected function backend(): Backend;

    /** True when the backend's output is a PDF; the contract only applies to PDF backends. */
    public static function producesPdf(Backend $backend): bool
    {
        $meta = new DocumentMeta(title: 'guard');
        return str_starts_with($backend->render($meta, [new PageDisplayList(100, 100, [])]), '%PDF-');
    }

    protected function setUp(): void
    {
        $this->backend = $this->backend();
        if (!self::producesPdf($this->backend)) {
            self::markTestSkipped($this->backend::class . ' does not produce PDF output; contract suite skipped');
        }
        $this->fonts = FontRegistry::withDefaults($this->backend);
    }

    private function meta(): DocumentMeta
    {
        return new DocumentMeta(title: 'Café ✓ Report', author: 'Zoë', lang: 'en-US', createdAt: new \DateTimeImmutable('2026-01-01T00:00:00Z'));
    }

    /** @param list<Op> $ops */
    private function render(array $ops, float $w = 595.0, float $h = 842.0): string
    {
        return $this->backend->render($this->meta(), [new PageDisplayList($w, $h, $ops)]);
    }

    /** @return list<string> output lines of a poppler tool run on $pdf */
    private function poppler(string $tool, string $pdf): array
    {
        $file = tempnam(sys_get_temp_dir(), 'pc');
        self::assertNotFalse($file);
        file_put_contents($file, $pdf);
        try {
            exec($tool . ' ' . escapeshellarg($file) . ' 2>&1', $lines, $code);
            self::assertSame(0, $code, "$tool failed:\n" . implode("\n", $lines));
            return $lines;
        } finally {
            @unlink($file);
        }
    }

    public function test_text_is_extractable_with_tounicode(): void
    {
        $font = $this->fonts->resolve('Inter');
        $pdf = $this->render([new TextRun(72, 100, 'Café Zoë contract', $font, 14, Color::black())]);
        self::assertStringContainsString('Café Zoë contract', PdfInspector::text($pdf));
        self::assertStringContainsString('/ToUnicode', PdfInspector::qdf($pdf));
    }

    public function test_fonts_are_embedded_and_subsetted(): void
    {
        $font = $this->fonts->resolve('Inter');
        $pdf = $this->render([new TextRun(72, 100, 'Subset me', $font, 14, Color::black())]);
        $rows = array_slice($this->poppler('pdffonts', $pdf), 2);
        self::assertNotEmpty($rows);
        foreach ($rows as $row) {
            self::assertMatchesRegularExpression('/\s+yes\s+(yes|no)\s+yes\s/', $row . ' ', "not embedded with ToUnicode: $row");
        }
        // Subsetting: pdffonts only reports 'sub' for tagged names, so assert it by size.
        $ttf = (int) filesize(__DIR__ . '/../../resources/fonts/Inter/Inter-Regular.ttf');
        self::assertLessThan($ttf * 0.25, strlen($pdf));
    }

    public function test_image_is_placed_at_op_geometry(): void
    {
        $png = $this->backend->loadImageBytes(TestImages::png());
        $qdf = PdfInspector::qdf($this->render([new DrawImage(100, 50, 80, 40, $png)]));
        // Top-left (100,50) size 80x40 on an 842pt page: matrix [80 0 0 40 100 752].
        self::assertMatchesRegularExpression('/\b80(\.0+)?\s+0(\.0+)?\s+0(\.0+)?\s+40(\.0+)?\s+100(\.0+)?\s+752(\.0+)?\s+cm\b/', $qdf);
        $list = $this->poppler('pdfimages -list', $this->render([new DrawImage(100, 50, 80, 40, $png)]));
        self::assertCount(3, $list, 'exactly one image listed');
    }

    public function test_notdef_advance_matches_drawn_width(): void
    {
        $font = $this->fonts->resolve('Inter');
        $text = 'AA中中中中AA';
        $w = $font->advance($text, 24);
        $pdf = $this->render([new TextRun(500 - $w, 100, $text, $font, 24, Color::black())]);
        // pdftotext splits the run at the .notdef glyphs; the last word carries the right edge.
        $words = PdfInspector::words($pdf);
        self::assertEqualsWithDelta(500.0, max(0.0, ...array_column($words, 'xMax')), 1.5);
    }

    public function test_text_lands_at_top_left_coordinates(): void
    {
        $font = $this->fonts->resolve('Inter');
        $pdf = $this->render([new TextRun(72, 100, 'Hello', $font, 12, Color::black())]);
        PdfInspector::check($pdf);
        $words = PdfInspector::words($pdf);
        self::assertSame('Hello', $words[0]['text']);
        self::assertEqualsWithDelta(72.0, $words[0]['xMin'], 0.5);
        self::assertLessThan(100.0, $words[0]['yMin']);
        self::assertGreaterThan(100.0, $words[0]['yMax']);
    }

    public function test_measured_advance_matches_drawn_width(): void
    {
        $font = $this->fonts->resolve('Inter');
        foreach ([0.0, 2.0] as $spacing) {
            $w = $font->advance('AVATAR', 24, $spacing);
            $pdf = $this->render([new TextRun(500 - $w, 100, 'AVATAR', $font, 24, Color::black(), $spacing)]);
            $word = PdfInspector::words($pdf)[0];
            // pdftotext's xMax includes trailing letter spacing; allow 1pt + spacing.
            self::assertEqualsWithDelta(500.0, $word['xMax'], 1.0 + $spacing, "letterSpacing=$spacing");
        }
    }

    public function test_every_op_type_produces_a_valid_pdf(): void
    {
        $font = $this->fonts->resolve('Inter', 700);
        $png = $this->backend->loadImageBytes(TestImages::png(alpha: true));
        $pdf = $this->render([
            new Rect(50, 50, 200, 100, fill: Color::parse('slate-100'), stroke: Color::parse('slate-400'), strokeWidth: 1, radius: 6),
            new Line(50, 170, 250, 170, Color::parse('red-500'), 0.5),
            new Line(50, 180, 250, 180, Color::parse('red-500'), 0.5, dashed: true),
            new DrawImage(300, 50, 80, 40, $png),
            new Clip(50, 200, 100, 20, [new TextRun(50, 215, 'Clipped long text that overflows', $font, 12, Color::black())]),
            new Opacity(0.5, [new Rect(300, 200, 50, 50, fill: Color::black())]),
            new Link(50, 50, 200, 100, 'https://example.com/papercut'),
        ]);
        PdfInspector::check($pdf);
        $qdf = PdfInspector::qdf($pdf);
        self::assertStringContainsString('/URI (https://example.com/papercut)', $qdf);
        self::assertStringContainsString('/SMask', $qdf);
    }

    public function test_alpha_color_draws_translucent(): void
    {
        $pdf = $this->render([new Rect(10, 10, 50, 50, fill: Color::parse('#33415580'))]);
        PdfInspector::check($pdf);
        self::assertMatchesRegularExpression('/\/ca 0?\.50?2?/', PdfInspector::qdf($pdf));
    }

    public function test_unicode_metadata_and_lang(): void
    {
        $pdf = $this->render([new Rect(0, 0, 10, 10, fill: Color::black())]);
        $info = PdfInspector::info($pdf);
        self::assertSame('Café ✓ Report', $info['Title']);
        self::assertSame('Zoë', $info['Author']);
        self::assertSame('Papercut', $info['Creator']);
        self::assertStringContainsString('/Lang (en-US)', PdfInspector::qdf($pdf));
    }

    public function test_empty_and_uncovered_text_do_not_break_output(): void
    {
        $font = $this->fonts->resolve('Inter');
        $pdf = $this->render([
            new TextRun(10, 20, '', $font, 12, Color::black()),
            new TextRun(10, 40, '   ', $font, 12, Color::black()),
            new TextRun(10, 60, '漢字 😀 ok', $font, 12, Color::black()),
        ]);
        PdfInspector::check($pdf);
        self::assertStringContainsString('ok', PdfInspector::text($pdf));
    }

    public function test_multiple_pages_and_page_sizes(): void
    {
        $font = $this->fonts->resolve('Inter');
        $pdf = $this->backend->render($this->meta(), [
            new PageDisplayList(595, 842, [new TextRun(72, 72, 'One', $font, 12, Color::black())]),
            new PageDisplayList(612, 792, [new TextRun(72, 72, 'Two', $font, 12, Color::black())]),
        ]);
        PdfInspector::check($pdf);
        self::assertSame('2', PdfInspector::info($pdf)['Pages']);
        self::assertSame('Two', PdfInspector::words($pdf, 2)[0]['text']);
    }

    public function test_output_is_deterministic(): void
    {
        $font = $this->fonts->resolve('Inter');
        $ops = [new TextRun(72, 72, 'Same bytes', $font, 12, Color::black())];
        self::assertSame($this->render($ops), $this->render($ops), 'same instance, repeated render');
        self::assertSame($this->render($ops), $this->backend()->render($this->meta(), [new PageDisplayList(595, 842, [
            new TextRun(72, 72, 'Same bytes', FontRegistry::withDefaults($this->backend())->resolve('Inter'), 12, Color::black()),
        ])]));
    }

    public function test_no_pages_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->backend->render($this->meta(), []);
    }

    public function test_foreign_font_handle_is_rejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->render([new TextRun(0, 10, 'x', new FakeFont(), 12, Color::black())]);
    }

    public function test_rendering_is_independent_of_previous_documents(): void
    {
        $pageB = static fn (FontRegistry $f): array => [new PageDisplayList(595, 842, [
            new TextRun(72, 72, 'Second document', $f->resolve('Inter'), 12, Color::black()),
        ])];
        $this->backend->render($this->meta(), [new PageDisplayList(595, 842, [
            new TextRun(72, 72, 'Completely different words 12345', $this->fonts->resolve('Inter'), 12, Color::black()),
        ])]);
        $reused = $this->backend->render($this->meta(), $pageB($this->fonts));

        $fresh = $this->backend();
        $expected = $fresh->render($this->meta(), $pageB(FontRegistry::withDefaults($fresh)));

        self::assertSame($expected, $reused);
    }

    /** @return array<string, array{string}> */
    public static function wrapperProvider(): array
    {
        return [
            'clip' => ['clip'],
            'opacity' => ['opacity'],
            'translucent colour' => ['alpha'],
        ];
    }

    #[DataProvider('wrapperProvider')]
    public function test_text_state_is_resynced_after_wrappers(string $kind): void
    {
        $font = $this->fonts->resolve('Inter');
        $red = Color::parse('red-500');
        $first = match ($kind) {
            'clip' => new Clip(0, 0, 500, 500, [new TextRun(10, 30, 'AAA', $font, 12, $red, 2.0)]),
            'opacity' => new Opacity(0.5, [new TextRun(10, 30, 'AAA', $font, 12, $red, 2.0)]),
            default => new TextRun(10, 30, 'AAA', $font, 12, Color::parse('#ef444480'), 2.0),
        };
        $qdf = PdfInspector::qdf($this->render([$first, new TextRun(10, 60, 'BBB', $font, 12, $red, 2.0)]));
        $offset = (int) strrpos($qdf, 'Td');
        $beforeSecond = substr($qdf, 0, $offset);
        $lastEt = (int) strrpos($beforeSecond, 'ET');
        $segment = substr($beforeSecond, $lastEt);
        self::assertStringContainsString(' rg', $segment);
        self::assertStringContainsString(' Tc', $segment);
    }

    public function test_nested_opacity_multiplies(): void
    {
        $qdf = PdfInspector::qdf($this->render([new Opacity(0.5, [new Rect(0, 0, 10, 10, fill: Color::parse('#00000080'))])]));
        self::assertMatchesRegularExpression('/\/ca 0?\.25/', $qdf);
        $qdf = PdfInspector::qdf($this->render([new Opacity(0.5, [new Opacity(0.5, [new Rect(0, 0, 10, 10, fill: Color::black())])])]));
        self::assertMatchesRegularExpression('/\/ca 0?\.25/', $qdf);
    }

    public function test_unknown_op_is_rejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->render([new class implements Op {}]);
    }

    public function test_foreign_image_handle_is_rejected(): void
    {
        $this->expectException(\LogicException::class);
        $img = new class implements \Papercut\Backend\ImageHandle {
            public function widthPx(): int { return 1; }
            public function heightPx(): int { return 1; }
            public function id(): string { return 'x'; }
        };
        $this->render([new DrawImage(0, 0, 10, 10, $img)]);
    }

    public function test_link_box_is_flipped(): void
    {
        $qdf = PdfInspector::qdf($this->render([new Link(50, 50, 200, 100, 'https://example.com')]));
        self::assertMatchesRegularExpression('/\/Rect\s*\[\s*50(\.0+)?\s+692(\.0+)?\s+250(\.0+)?\s+792(\.0+)?\s*\]/', $qdf);
    }
}
