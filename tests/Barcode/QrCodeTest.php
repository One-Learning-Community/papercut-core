<?php

declare(strict_types=1);

namespace Papercut\Tests\Barcode;

use Papercut\Barcode\{ErrorCorrection, ModuleRects, QrEncoder};
use Papercut\{Document, Page, QrCode, Row};
use Papercut\Exception\InvalidValue;
use Papercut\Export\HtmlOptions;
use Papercut\Layout\{Fragment, Status};
use Papercut\Paint\Rect;
use Papercut\Tests\Support\{LayoutHarness, PdfInspector};
use PHPUnit\Framework\TestCase;
use function Papercut\{mm, percent, pt};

final class QrCodeTest extends TestCase
{
    private const string URL = 'https://acme.test/pay/INV-142';

    /** @return list<Rect> */
    private static function rects(Fragment $f): array
    {
        // The box's own background is a rect the size of the whole box; modules are smaller.
        return array_values(array_filter($f->ops(), static fn (object $op): bool => $op instanceof Rect && $op->fill !== null && $op->width < $f->width));
    }

    /**
     * Paints the rectangles back onto a grid of modules.
     *
     * @param list<Rect> $rects
     * @return array<int, array<int, bool>> keyed like the encoder's rows, so assertSame compares them
     */
    private static function rasterize(array $rects, int $size, float $originX, float $originY, float $module): array
    {
        $grid = array_fill(0, $size, array_fill(0, $size, false));
        foreach ($rects as $r) {
            $x0 = (int) round(($r->x - $originX) / $module);
            $y0 = (int) round(($r->y - $originY) / $module);
            $w = (int) round($r->width / $module);
            $h = (int) round($r->height / $module);
            for ($y = $y0; $y < $y0 + $h; $y++) {
                for ($x = $x0; $x < $x0 + $w; $x++) {
                    self::assertFalse($grid[$y][$x], "module $x,$y is covered twice");
                    $grid[$y][$x] = true;
                }
            }
        }
        return $grid;
    }

    public function test_make_encodes_the_text_into_a_square_grid(): void
    {
        $qr = QrCode::make(self::URL);

        $n = count($qr->modules());
        $this->assertGreaterThanOrEqual(21, $n);
        foreach ($qr->modules() as $row) {
            $this->assertCount($n, $row);
        }
        $this->assertSame($n + 8, $qr->span());
        $this->assertSame(ErrorCorrection::Medium, $qr->level());
        $this->assertSame(self::URL, $qr->value());
    }

    public function test_the_rectangles_cover_exactly_the_dark_modules(): void
    {
        $qr = QrCode::make(self::URL)->width(mm(40));
        $f = (new LayoutHarness())->layout($qr, 500)->fragment;
        $this->assertNotNull($f);

        $side = mm(40)->points;
        $this->assertEqualsWithDelta($side, $f->width, 1e-6);
        $this->assertEqualsWithDelta($side, $f->height, 1e-6);
        $module = $side / $qr->span();
        $grid = self::rasterize(self::rects($f), count($qr->modules()), 4 * $module, 4 * $module, $module);
        $this->assertSame($qr->modules(), $grid);
    }

    public function test_module_rects_merge_runs_and_rows(): void
    {
        $grid = [
            [true, true, false, true],
            [true, true, false, true],
            [false, true, true, true],
            [false, false, false, false],
        ];

        $this->assertSame([
            ['x' => 0, 'y' => 0, 'w' => 2, 'h' => 2],
            ['x' => 3, 'y' => 0, 'w' => 1, 'h' => 2],
            ['x' => 1, 'y' => 2, 'w' => 3, 'h' => 1],
        ], ModuleRects::of($grid));
    }

    public function test_default_size_is_25mm_and_width_or_height_sizes_it(): void
    {
        $h = new LayoutHarness();

        $this->assertEqualsWithDelta(mm(25)->points, $h->layout(QrCode::make('x'), 500)->fragment?->width, 1e-6);
        $this->assertEqualsWithDelta(mm(30)->points, $h->layout(QrCode::make('x')->height(mm(30)), 500)->fragment?->width, 1e-6);
        $this->assertEqualsWithDelta(250.0, $h->layout(QrCode::make('x')->width(percent(50)), 500)->fragment?->height, 1e-6);
        $this->assertEqualsWithDelta(mm(20)->points, $h->layout(QrCode::make('x')->width(mm(40))->maxWidth(mm(20)), 500)->fragment?->width, 1e-6);
    }

    public function test_it_shrinks_to_the_width_available(): void
    {
        $f = (new LayoutHarness())->layout(QrCode::make('x')->width(mm(100)), 50)->fragment;

        $this->assertEqualsWithDelta(50.0, $f?->width, 1e-6);
        $this->assertEqualsWithDelta(50.0, $f?->height, 1e-6);
    }

    public function test_it_moves_to_the_next_page_rather_than_split(): void
    {
        $h = new LayoutHarness();

        $this->assertSame(Status::DoesNotFit, $h->layout(QrCode::make('x'), 500, 20.0, fresh: false)->status);
        // On an empty page a default-sized code shrinks to fit; an explicit size does not.
        $this->assertEqualsWithDelta(20.0, $h->layout(QrCode::make('x'), 500, 20.0)->fragment?->height, 1e-6);
        $this->assertSame(Status::DoesNotFit, $h->layout(QrCode::make('x')->width(mm(30)), 500, 20.0)->status);
    }

    public function test_padding_and_border_sit_inside_the_size(): void
    {
        $qr = QrCode::make(self::URL)->width(pt(100))->padding(pt(10))->background('slate-50');
        $f = (new LayoutHarness())->layout($qr, 500)->fragment;
        $this->assertNotNull($f);

        $module = 80 / $qr->span();
        $grid = self::rasterize(self::rects($f), count($qr->modules()), 10 + 4 * $module, 10 + 4 * $module, $module);
        $this->assertSame($qr->modules(), $grid);
    }

    public function test_modules_use_the_text_color(): void
    {
        $f = (new LayoutHarness())->layout(QrCode::make('x')->color('indigo-700'), 500)->fragment;
        $this->assertNotNull($f);

        $this->assertEquals(\Papercut\Value\Color::parse('indigo-700'), self::rects($f)[0]->fill);
    }

    public function test_quiet_zone_can_change(): void
    {
        $qr = QrCode::make('x');

        $this->assertSame(count($qr->modules()), $qr->quietZone(0)->span());
        $this->assertSame($qr->span(), QrCode::DEFAULT_QUIET_ZONE * 2 + count($qr->modules()));
        $this->expectException(InvalidValue::class);
        $qr->quietZone(-1);
    }

    public function test_higher_error_correction_needs_more_modules_and_keeps_style(): void
    {
        $low = QrCode::make(self::URL, ErrorCorrection::Low)->width(mm(40));
        $high = $low->errorCorrection(ErrorCorrection::High);

        $this->assertGreaterThan(count($low->modules()), count($high->modules()));
        $this->assertSame(ErrorCorrection::High, $high->level());
        $this->assertEquals($low->style(), $high->style());
    }

    public function test_text_too_long_is_explained(): void
    {
        $this->expectException(InvalidValue::class);
        $this->expectExceptionMessage('too long at medium error correction; the most is 213 bytes');

        QrCode::make(str_repeat('x', 300));
    }

    public function test_empty_text_is_rejected(): void
    {
        $this->expectException(InvalidValue::class);

        QrCode::make('');
    }

    public function test_a_custom_encoder_or_grid_can_be_used(): void
    {
        $grid = [[true, false], [false, true]];
        $encoder = new class ($grid) implements QrEncoder {
            /** @param non-empty-list<non-empty-list<bool>> $grid */
            public function __construct(private readonly array $grid)
            {
            }

            public function encode(string $data, ErrorCorrection $level): array
            {
                return $this->grid;
            }
        };

        $this->assertSame($grid, QrCode::make('anything', encoder: $encoder)->modules());
        $this->assertSame($grid, QrCode::fromModules([[1, 0], [0, 1]])->modules());
    }

    public function test_a_grid_must_be_square(): void
    {
        $this->expectException(InvalidValue::class);
        $this->expectExceptionMessage('each row needs 2 modules');

        QrCode::fromModules([[true, false], [true]]);
    }

    public function test_a_grid_has_no_text_to_encode_again(): void
    {
        $this->expectException(\LogicException::class);

        QrCode::fromModules([[true]])->errorCorrection(ErrorCorrection::High);
    }

    public function test_html_is_an_inline_svg_in_module_units(): void
    {
        $qr = QrCode::make(self::URL);
        $html = Document::make(Page::a4()->body(Row::make($qr->width(mm(30)))))->toHtml(new HtmlOptions())->bytes();

        $this->assertMatchesRegularExpression(sprintf('/<svg viewBox="0 0 %1$d %1$d" role="img" aria-label="QR code: %2$s"/', $qr->span(), preg_quote(self::URL, '/')), $html);
        $this->assertSame(count(ModuleRects::of($qr->modules())), substr_count($html, 'z', (int) strpos($html, '<path d="')) - substr_count($html, 'z', (int) strpos($html, '"/></svg>')));
    }

    public function test_the_pdf_is_valid(): void
    {
        PdfInspector::check(Document::make(Page::a4()->body(QrCode::make(self::URL)))->toPdf()->bytes());
    }
}
