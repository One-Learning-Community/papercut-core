<?php

declare(strict_types=1);

namespace Papercut\Tests\Feature;

use Papercut\{Document, Page, Svg, Text};
use Papercut\Export\PdfOptions;
use Papercut\Tests\Support\PdfInspector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use function Papercut\pt;

/**
 * SVG drawn into real PDFs and rasterised with pdftoppm. Every fixture has a 100 x 100 view box
 * drawn 100pt wide on a 100pt page with no margin, so at 72 dpi a user unit is a pixel.
 */
final class SvgPdfTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../fixtures/svg/';

    protected function setUp(): void
    {
        exec('command -v pdftoppm 2>/dev/null', $out, $code);
        if ($code !== 0) {
            self::markTestSkipped('pdftoppm (poppler) is not installed.');
        }
        if (!extension_loaded('gd')) {
            self::markTestSkipped('GD is needed to read the rasterised page.');
        }
    }

    private static function pdf(Svg $svg, ?PdfOptions $options = null): string
    {
        $page = Page::size(pt(100), pt(100))->margin(pt(0))->body($svg->width(pt(100)));

        return Document::make($page)->toPdf($options)->bytes();
    }

    private static function raster(string $pdf): \GdImage
    {
        $dir = sys_get_temp_dir() . '/papercut-svg-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            file_put_contents("$dir/doc.pdf", $pdf);
            exec(sprintf('pdftoppm -png -r 72 -singlefile %s %s 2>&1', escapeshellarg("$dir/doc.pdf"), escapeshellarg("$dir/page")), $out, $code);
            self::assertSame(0, $code, implode("\n", $out));
            $image = imagecreatefrompng("$dir/page.png");
            self::assertInstanceOf(\GdImage::class, $image);
            return $image;
        } finally {
            array_map('unlink', glob("$dir/*") ?: []);
            rmdir($dir);
        }
    }

    /** True when the pixel is dark ink, false when it is paper. */
    private static function ink(\GdImage $image, int $x, int $y): bool
    {
        $rgb = imagecolorat($image, $x, $y);
        $grey = ((($rgb >> 16) & 0xFF) + (($rgb >> 8) & 0xFF) + ($rgb & 0xFF)) / 3;

        return $grey < 128;
    }

    /** @return iterable<string, array{string, list<array{int, int}>, list<array{int, int}>}> */
    public static function evenOdd(): iterable
    {
        // fixture => [points that must be inked, points that must be empty]
        yield 'donut' => ['donut.svg', [[50, 20], [50, 80], [15, 50]], [[50, 50], [40, 50]]];
        yield 'single-path letter O' => ['letter-o.svg', [[50, 15], [20, 50], [80, 50]], [[50, 50], [40, 40]]];
        yield 'five-point star' => ['star.svg', [[50, 20], [15, 44], [85, 44]], [[50, 55], [44, 50]]];
        yield 'nested squares' => ['nested-squares.svg', [[50, 10], [50, 50], [10, 50]], [[50, 27], [27, 50], [73, 50]]];
        yield 'fill-rule on a group' => ['group-evenodd.svg', [[15, 50], [50, 15]], [[50, 50]]];
        yield 'nonzero leaves no hole' => ['donut-nonzero.svg', [[50, 20], [50, 50]], []];
    }

    /**
     * @param list<array{int, int}> $inked
     * @param list<array{int, int}> $empty
     */
    #[DataProvider('evenOdd')]
    public function test_even_odd_shapes_keep_their_holes(string $fixture, array $inked, array $empty): void
    {
        $image = self::raster(self::pdf(Svg::file(self::FIXTURES . $fixture)));

        foreach ($inked as [$x, $y]) {
            self::assertTrue(self::ink($image, $x, $y), "$fixture should be inked at $x,$y");
        }
        foreach ($empty as [$x, $y]) {
            self::assertFalse(self::ink($image, $x, $y), "$fixture should be empty at $x,$y");
        }
    }

    public function test_the_drawing_is_placed_where_layout_puts_it(): void
    {
        // A 20pt-tall line of text pushes the square down; it is drawn 40pt wide, so 0.4pt a unit.
        $page = Page::size(pt(100), pt(100))->margin(pt(0))->body(
            Text::make('x')->height(pt(20)),
            Svg::fromString('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect width="100" height="100" fill="#000"/></svg>')->width(pt(40)),
        );
        $image = self::raster(Document::make($page)->toPdf()->bytes());

        self::assertFalse(self::ink($image, 20, 15));
        self::assertTrue(self::ink($image, 20, 40));
        self::assertTrue(self::ink($image, 38, 58));
        self::assertFalse(self::ink($image, 45, 40));
        self::assertFalse(self::ink($image, 20, 65));
    }

    public function test_drawing_outside_the_view_box_is_clipped(): void
    {
        $svg = Svg::fromString('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 50 50"><rect x="-50" y="-50" width="200" height="200" fill="#000"/></svg>')->width(pt(50));
        $page = Page::size(pt(100), pt(100))->margin(pt(0))->body($svg);
        $image = self::raster(Document::make($page)->toPdf()->bytes());

        self::assertTrue(self::ink($image, 25, 25));
        self::assertFalse(self::ink($image, 75, 25));
        self::assertFalse(self::ink($image, 25, 75));
    }

    public function test_svg_text_is_set_in_an_embedded_font_never_a_base_14_one(): void
    {
        $svg = Svg::fromString('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><text x="5" y="30" font-family="Helvetica, Arial, sans-serif" font-size="12">Paid in full</text><text x="5" y="60" font-weight="bold">Оплачено</text></svg>');
        $pdf = self::pdf($svg);

        PdfInspector::check($pdf);
        self::assertStringContainsString('Paid in full', PdfInspector::text($pdf));
        self::assertStringContainsString('Оплачено', PdfInspector::text($pdf));
        $fonts = self::fonts($pdf);
        self::assertNotSame([], $fonts);
        foreach ($fonts as $font) {
            self::assertStringContainsString('Inter', $font['name']);
            self::assertSame('yes', $font['embedded'], $font['name'] . ' should be embedded');
        }
    }

    public function test_svg_text_uses_a_family_the_document_registered(): void
    {
        $svg = Svg::fromString('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><text x="5" y="30" font-family="\'Brand Sans\', serif">Brand</text></svg>');
        $inter = __DIR__ . '/../../resources/fonts/Inter/Inter-Bold.ttf';
        $pdf = self::pdf($svg, new PdfOptions(fonts: (new \Papercut\Text\FontSet())->with(new \Papercut\Text\FontFace('Brand Sans', $inter, 400))));

        $names = array_column(self::fonts($pdf), 'name');
        self::assertCount(1, $names);
        self::assertStringContainsString('Bold', $names[0]);
    }

    public function test_a_warning_names_what_the_pdf_leaves_out(): void
    {
        $warnings = [];
        $svg = Svg::fromString('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><filter id="f"/><rect width="10" height="10" filter="url(#f)"/></svg>');
        self::pdf($svg, new PdfOptions(onWarning: static function (string $w) use (&$warnings): void {
            $warnings[] = $w;
        }));

        self::assertCount(1, $warnings);
        self::assertStringContainsString('<filter>', $warnings[0]);
    }

    /** @return list<array{name: string, embedded: string}> */
    private static function fonts(string $pdf): array
    {
        $file = tempnam(sys_get_temp_dir(), 'pdf');
        self::assertIsString($file);
        try {
            file_put_contents($file, $pdf);
            exec('pdffonts ' . escapeshellarg($file) . ' 2>&1', $lines, $code);
            self::assertSame(0, $code, implode("\n", $lines));
        } finally {
            unlink($file);
        }
        $fonts = [];
        foreach (array_slice($lines, 2) as $line) {
            $cols = preg_split('/\s+/', trim($line)) ?: [];
            // name type [type...] encoding emb sub uni object ID: emb is the fifth column from the end.
            $fonts[] = ['name' => $cols[0], 'embedded' => $cols[count($cols) - 5] ?? ''];
        }
        return $fonts;
    }
}
