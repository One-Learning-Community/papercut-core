<?php

declare(strict_types=1);

namespace Papercut\Tests\Barcode;

use Papercut\Barcode\{ErrorCorrection, QrEyes, QrGradient, QrShape};
use Papercut\{Document, Page, QrCode};
use Papercut\Exception\InvalidValue;
use Papercut\Export\HtmlOptions;
use Papercut\Paint\{DrawSvg, Rect};
use Papercut\Tests\Support\{LayoutHarness, PdfInspector};
use Papercut\Value\Color;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use function Papercut\pt;

/**
 * Styled QR codes: Papercut encodes the grid, and BaconQrCode draws it as an SVG with the
 * chosen module shape, eyes and colours. A plain code still draws as rectangles.
 */
final class QrDesignTest extends TestCase
{
    private const string URL = 'https://acme.test/pay/INV-142';

    public function test_a_plain_code_has_no_design_and_draws_rectangles(): void
    {
        $qr = QrCode::make(self::URL);
        $f = (new LayoutHarness())->layout($qr, 500)->fragment;

        self::assertNull($qr->design());
        self::assertNotNull($f);
        self::assertNotEmpty(array_filter($f->ops(), static fn (object $op): bool => $op instanceof Rect));
        self::assertSame([], array_filter($f->ops(), static fn (object $op): bool => $op instanceof DrawSvg));
    }

    public function test_a_styled_code_is_one_svg_covering_the_box(): void
    {
        $qr = QrCode::make(self::URL)->shape(QrShape::Dots)->width(pt(100))->padding(pt(5));
        $f = (new LayoutHarness())->layout($qr, 500)->fragment;
        self::assertNotNull($f);

        $draws = array_values(array_filter($f->ops(), static fn (object $op): bool => $op instanceof DrawSvg));
        self::assertCount(1, $draws);
        self::assertSame([], array_filter($f->ops(), static fn (object $op): bool => $op instanceof Rect && $op->width < 90));
        // The quiet zone is inside the drawing, so it fills the content box.
        self::assertEqualsWithDelta([5.0, 5.0, 90.0, 90.0], [$draws[0]->x, $draws[0]->y, $draws[0]->width, $draws[0]->height], 1e-9);
        self::assertSame([0.0, 0.0, (float) $qr->span(), (float) $qr->span()], $draws[0]->svg->viewBox);
        self::assertSame([], $draws[0]->svg->unsupported);
    }

    public function test_the_design_is_kept_by_every_change(): void
    {
        $qr = QrCode::make(self::URL)->shape(QrShape::Rounded)->eyes(QrEyes::Dot)->eyeColor('#e11d48')->gradient('#4f46e5', '#0ea5e9', QrGradient::Radial);
        $design = $qr->design();
        self::assertNotNull($design);
        self::assertSame(QrShape::Rounded, $design->shape);
        self::assertSame(QrEyes::Dot, $design->eyes);
        self::assertEquals(Color::hex('#e11d48'), $design->eyeColor);
        self::assertEquals([Color::hex('#4f46e5'), Color::hex('#0ea5e9')], [$design->gradientFrom, $design->gradientTo]);
        self::assertSame(QrGradient::Radial, $design->gradientType);

        self::assertEquals($design, $qr->errorCorrection(ErrorCorrection::High)->design());
        self::assertEquals($design, $qr->quietZone(2)->design());
        self::assertEquals($design, $qr->width(pt(80))->design());
    }

    /** @return iterable<string, array{QrShape}> */
    public static function inkLightShapes(): iterable
    {
        yield 'rounded' => [QrShape::Rounded];
        yield 'dots' => [QrShape::Dots];
    }

    /** Dots and rounded modules leave less ink, so they need quartile error correction to scan. */
    #[DataProvider('inkLightShapes')]
    public function test_dots_and_rounded_modules_raise_the_level_to_quartile(QrShape $shape): void
    {
        $qr = QrCode::make(self::URL)->shape($shape);

        self::assertSame(ErrorCorrection::Quartile, $qr->level());
        self::assertSame(QrCode::make(self::URL, ErrorCorrection::Quartile)->modules(), $qr->modules());
        self::assertSame(ErrorCorrection::Quartile, QrCode::make(self::URL, ErrorCorrection::Low)->shape($shape)->level());
    }

    public function test_high_stays_high(): void
    {
        self::assertSame(ErrorCorrection::High, QrCode::make(self::URL, ErrorCorrection::High)->shape(QrShape::Dots)->level());
    }

    public function test_the_level_cannot_drop_below_quartile_afterwards(): void
    {
        $qr = QrCode::make(self::URL)->shape(QrShape::Dots);

        self::assertSame(ErrorCorrection::Quartile, $qr->errorCorrection(ErrorCorrection::Low)->level());
        self::assertSame(ErrorCorrection::High, $qr->errorCorrection(ErrorCorrection::High)->level());
    }

    public function test_square_modules_eyes_and_colors_keep_the_level(): void
    {
        $qr = QrCode::make(self::URL)->shape(QrShape::Square)->eyes(QrEyes::Pointy)->eyeColor('#e11d48')->gradient('#000', '#333');

        self::assertSame(ErrorCorrection::Medium, $qr->level());
    }

    public function test_text_too_long_for_quartile_says_why_the_level_rose(): void
    {
        $text = str_repeat('x', 200);
        QrCode::make($text);

        $this->expectException(InvalidValue::class);
        $this->expectExceptionMessage('dots and rounded modules need quartile error correction or higher to scan reliably');

        QrCode::make($text)->shape(QrShape::Dots);
    }

    public function test_a_grid_you_encoded_keeps_its_modules(): void
    {
        $grid = QrCode::make(self::URL)->modules();

        self::assertSame($grid, QrCode::fromModules($grid)->shape(QrShape::Dots)->modules());
    }

    public function test_the_gradient_is_diagonal_unless_you_choose(): void
    {
        self::assertSame(QrGradient::Diagonal, QrCode::make('x')->gradient('#000', '#333')->design()?->gradientType);
    }

    public function test_the_svg_uses_the_text_color_and_no_background(): void
    {
        $xml = self::svg(QrCode::make(self::URL)->shape(QrShape::Dots)->color('#1e293b'));

        self::assertStringContainsString('fill="#1e293b"', $xml);
        self::assertStringNotContainsString('<rect', $xml);
    }

    public function test_eye_color_and_gradient_reach_the_svg(): void
    {
        $xml = self::svg(QrCode::make(self::URL)->eyeColor('#e11d48')->gradient('#4f46e5', '#0ea5e9'));

        self::assertStringContainsString('fill="#e11d48"', $xml);
        self::assertStringContainsString('<linearGradient', $xml);
        self::assertStringContainsString('stop-color="#4f46e5"', $xml);
        self::assertStringContainsString('<radialGradient', self::svg(QrCode::make(self::URL)->gradient('#000', '#444', QrGradient::Radial)));
    }

    public function test_a_transparent_color_draws_nothing(): void
    {
        $f = (new LayoutHarness())->layout(QrCode::make(self::URL)->shape(QrShape::Dots)->color(Color::transparent()), 500)->fragment;

        self::assertNotNull($f);
        self::assertSame([], array_filter($f->ops(), static fn (object $op): bool => $op instanceof DrawSvg));
    }

    public function test_a_grid_you_encoded_can_be_styled(): void
    {
        $grid = QrCode::make(self::URL)->modules();

        self::assertNotNull(QrCode::fromModules($grid)->shape(QrShape::Dots)->design());
    }

    public function test_styling_needs_a_standard_qr_grid(): void
    {
        $this->expectException(InvalidValue::class);
        $this->expectExceptionMessage('21 to 177 modules');

        QrCode::fromModules(array_fill(0, 22, array_fill(0, 22, true)))->shape(QrShape::Dots);
    }

    /** @return iterable<string, array{QrShape}> */
    public static function shapes(): iterable
    {
        foreach (QrShape::cases() as $shape) {
            yield $shape->value => [$shape];
        }
    }

    /** Every dark module is inked at its centre and every light one is paper, whatever the shape. */
    #[DataProvider('shapes')]
    public function test_the_pdf_draws_exactly_our_grid(QrShape $shape): void
    {
        $qr = QrCode::make(self::URL)->shape($shape);
        $image = self::raster($qr, 8);
        $q = $qr->quietZoneModules();
        $wrong = [];
        foreach ($qr->modules() as $y => $row) {
            foreach ($row as $x => $dark) {
                if (self::ink($image, ($x + $q) * 8 + 4, ($y + $q) * 8 + 4) !== $dark) {
                    $wrong[] = "$x,$y";
                }
            }
        }
        self::assertSame([], $wrong);
    }

    public function test_the_eyes_take_their_own_colour_in_the_pdf(): void
    {
        $qr = QrCode::make(self::URL)->shape(QrShape::Dots)->eyeColor('#ff0000')->color('#000000');
        $image = self::raster($qr, 8);
        $q = $qr->quietZoneModules();

        // The top-left eye's outer ring and centre are red; a data module stays black.
        foreach ([[0, 0], [3, 3], [6, 0]] as [$x, $y]) {
            self::assertSame([255, 0, 0], self::rgb($image, ($x + $q) * 8 + 4, ($y + $q) * 8 + 4), "eye module $x,$y");
        }
        [$dx, $dy] = self::darkDataModule($qr->modules());
        self::assertSame([0, 0, 0], self::rgb($image, ($dx + $q) * 8 + 4, ($dy + $q) * 8 + 4));
    }

    public function test_a_diagonal_gradient_runs_from_top_left_to_bottom_right(): void
    {
        $qr = QrCode::make(self::URL)->gradient('#ff0000', '#0000ff');
        $image = self::raster($qr, 8);
        $q = $qr->quietZoneModules();
        // The dark module nearest the bottom-right corner, which has no eye.
        $modules = $qr->modules();
        $last = [0, 0];
        foreach ($modules as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark && $x + $y >= $last[0] + $last[1]) {
                    $last = [$x, $y];
                }
            }
        }

        [$r1, , $b1] = self::rgb($image, $q * 8 + 4, $q * 8 + 4);
        [$r2, , $b2] = self::rgb($image, ($last[0] + $q) * 8 + 4, ($last[1] + $q) * 8 + 4);
        self::assertGreaterThan($b1, $r1, 'top left is mostly red');
        self::assertGreaterThan($r2, $b2, 'bottom right is mostly blue');
    }

    public function test_the_styled_pdf_is_valid(): void
    {
        PdfInspector::check(Document::make(Page::a4()->body(QrCode::make(self::URL)->shape(QrShape::Dots)->eyes(QrEyes::Dot)->gradient('#4f46e5', '#0ea5e9')))->toPdf()->bytes());
    }

    public function test_html_shows_the_same_svg_as_an_image(): void
    {
        $html = Document::make(Page::a4()->body(QrCode::make(self::URL)->shape(QrShape::Dots)->eyeColor('#e11d48')))->toHtml(new HtmlOptions())->bytes();

        self::assertSame(1, preg_match('@<img src="data:image/svg\+xml;base64,([^"]+)" alt="QR code: ' . preg_quote(self::URL, '@') . '"[^>]*style="[^"]*aspect-ratio: ?1@', $html, $m));
        self::assertStringContainsString('fill="#e11d48"', (string) base64_decode($m[1] ?? "", true));
        self::assertStringNotContainsString('<svg viewBox', $html);
    }

    public function test_without_bacon_styling_says_how_to_install_it(): void
    {
        $script = sprintf(<<<'PHP'
            $loader = require %s;
            $loader->unregister();
            spl_autoload_register(static function (string $class) use ($loader): void {
                if (!str_starts_with($class, 'BaconQrCode\\')) {
                    $loader->loadClass($class);
                }
            });
            echo Papercut\QrCode::make('x')->modules() !== [] ? "plain ok\n" : '';
            try {
                Papercut\QrCode::make('x')->shape(Papercut\Barcode\QrShape::Dots);
            } catch (Papercut\Exception\MissingPackage $e) {
                echo $e->getMessage(), "\n";
            }
            try {
                new Papercut\Barcode\Bacon\BaconQrEncoder();
            } catch (Papercut\Exception\MissingPackage $e) {
                echo $e->getMessage();
            }
            PHP, var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true));
        exec(sprintf('%s -r %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($script)), $out);

        self::assertSame([
            'plain ok',
            'Styled QR codes need bacon/bacon-qr-code: run "composer require bacon/bacon-qr-code".',
            'BaconQrEncoder needs bacon/bacon-qr-code: run "composer require bacon/bacon-qr-code".',
        ], $out);
    }

    private static function svg(QrCode $qr): string
    {
        $f = (new LayoutHarness())->layout($qr, 500)->fragment;
        self::assertNotNull($f);
        foreach ($f->ops() as $op) {
            if ($op instanceof DrawSvg) {
                return $op->svg->xml;
            }
        }
        self::fail('No SVG was drawn.');
    }

    /** The code drawn at $px pixels a module, on a page exactly its size, at 72 dpi. */
    private static function raster(QrCode $qr, int $px): \GdImage
    {
        exec('command -v pdftoppm 2>/dev/null', $out, $code);
        if ($code !== 0 || !extension_loaded('gd')) {
            self::markTestSkipped('pdftoppm (poppler) and GD are needed to read the rasterised page.');
        }
        $side = pt($qr->span() * $px);
        $pdf = Document::make(Page::size($side, $side)->margin(pt(0))->body($qr->width($side)))->toPdf()->bytes();
        $dir = sys_get_temp_dir() . '/papercut-qr-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            file_put_contents("$dir/doc.pdf", $pdf);
            exec(sprintf('pdftoppm -png -r 72 -singlefile %s %s 2>&1', escapeshellarg("$dir/doc.pdf"), escapeshellarg("$dir/page")), $lines, $status);
            self::assertSame(0, $status, implode("\n", $lines));
            $image = imagecreatefrompng("$dir/page.png");
            self::assertInstanceOf(\GdImage::class, $image);

            return $image;
        } finally {
            array_map('unlink', glob("$dir/*") ?: []);
            rmdir($dir);
        }
    }

    private static function ink(\GdImage $image, int $x, int $y): bool
    {
        [$r, $g, $b] = self::rgb($image, $x, $y);

        return ($r + $g + $b) / 3 < 128;
    }

    /** @return array{int, int, int} */
    private static function rgb(\GdImage $image, int $x, int $y): array
    {
        $c = imagecolorat($image, $x, $y);

        return [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];
    }

    /**
     * A dark module away from the three eyes.
     *
     * @param non-empty-list<non-empty-list<bool>> $modules
     * @return array{int, int}
     */
    private static function darkDataModule(array $modules): array
    {
        $n = count($modules);
        for ($y = 9; $y < $n - 9; $y++) {
            for ($x = 9; $x < $n - 9; $x++) {
                if ($modules[$y][$x]) {
                    return [$x, $y];
                }
            }
        }
        self::fail('The code has no dark data module.');
    }
}
