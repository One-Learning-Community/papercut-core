<?php

declare(strict_types=1);

namespace Papercut\Backend\PhpPdf;

use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Image\PdfImage;
use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Pdf\Page as PdfPage;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Papercut\Backend\Backend;
use Papercut\Backend\FontHandle;
use Papercut\Backend\ImageHandle;
use Papercut\Backend\ImageFormat;
use Papercut\Exception\FontNotFound;
use Papercut\Exception\ImageUnreadable;
use Papercut\Paint\{Clip, DocumentMeta, DrawImage, Line, Link, Op, Opacity, PageDisplayList, Rect, TextRun};
use Papercut\Text\CachedFont;
use Papercut\Value\Color;

final class PhpPdfBackend implements Backend
{
    private const array TEXT_STATE_PROPERTIES = ['lastFillR', 'lastFillG', 'lastFillB', 'lastCharSpacing'];

    /** @var array<string, PhpPdfFont> */
    private array $fonts = [];
    private readonly KerningCache $kerning;
    private readonly GdImageConverter $gd;

    /**
     * $kerningCacheDir: where GPOS pair-kerning tables are cached across processes
     * (null = KerningCache::defaultDir(), false = no disk cache).
     * $gd: converts WebP, GIF, BMP and AVIF (null = use GD when it is loaded).
     */
    public function __construct(string|false|null $kerningCacheDir = null, ?GdImageConverter $gd = null)
    {
        $this->kerning = new KerningCache($kerningCacheDir === false ? null : ($kerningCacheDir ?? KerningCache::defaultDir()));
        $this->gd = $gd ?? GdImageConverter::detect();
    }

    public function loadFont(string $path): FontHandle
    {
        $real = realpath($path);
        if ($real === false || !is_readable($real) || !is_file($real)) {
            throw new FontNotFound('Font file not found: ' . $path);
        }
        if (isset($this->fonts[$real])) {
            return $this->fonts[$real];
        }
        try {
            $font = new PdfFont(TtfFile::fromFile($real));
            $font->ttf()->unitsPerEm();
        } catch (\Throwable $e) {
            throw new FontNotFound('Font file is not a valid TrueType/OpenType font: ' . $path, 0, $e);
        }
        $this->kerning->prime($font->ttf(), $real);
        return $this->fonts[$real] = new PhpPdfFont($font, sha1($real));
    }

    public function loadImageFile(string $path): ImageHandle
    {
        $bytes = is_file($path) && is_readable($path) ? file_get_contents($path) : false;
        if ($bytes === false) {
            throw new ImageUnreadable(sprintf('Cannot read image "%s": file not found or not readable', $path));
        }
        try {
            return $this->image($bytes);
        } catch (\Throwable $e) {
            throw new ImageUnreadable(sprintf('Cannot read image "%s": %s', $path, $e->getMessage()), 0, $e);
        }
    }

    public function loadImageBytes(string $bytes): ImageHandle
    {
        try {
            return $this->image($bytes);
        } catch (\Throwable $e) {
            throw new ImageUnreadable('Cannot read image bytes: ' . $e->getMessage(), 0, $e);
        }
    }

    /** PNG and JPEG go to php-pdf as they are; formats it cannot read are converted to PNG first. */
    private function image(string $bytes): PhpPdfImage
    {
        $mime = ImageFormat::sniff($bytes);
        if ($mime === null) {
            throw new \RuntimeException(ImageFormat::unsupported());
        }
        $embed = $this->gd->handles($mime) ? $this->gd->toPng($bytes, $mime) : $bytes;
        return new PhpPdfImage(PdfImage::fromBytes($embed), sha1($bytes));
    }

    public function render(DocumentMeta $meta, array $pages): string
    {
        if ($pages === []) {
            throw new \InvalidArgumentException('Cannot render a document with no pages.');
        }
        foreach ($this->fonts as $font) {
            $font->pdfFont()->reset();
        }
        $doc = PdfDocument::new();
        $doc->metadata(
            title: $meta->title,
            author: $meta->author,
            subject: $meta->subject,
            keywords: $meta->keywords,
            creator: 'Papercut',
            producer: 'Papercut',
            creationDate: $meta->createdAt ?? new \DateTimeImmutable(),
        );
        if ($meta->lang !== null) {
            $doc->setLang($meta->lang);
        }
        foreach ($pages as $page) {
            $pdfPage = $doc->addPage(customDimensionsPt: [$page->width, $page->height]);
            $this->drawOps($pdfPage, $page->height, $page->ops);
        }
        return $doc->toBytes();
    }

    /** @param list<Op> $ops */
    private function drawOps(PdfPage $p, float $h, array $ops, float $inherited = 1.0): void
    {
        foreach ($ops as $op) {
            match (true) {
                $op instanceof TextRun => $this->drawText($p, $h, $op, $inherited),
                $op instanceof Rect => $this->drawRect($p, $h, $op, $inherited),
                $op instanceof Line => $this->drawLine($p, $h, $op, $inherited),
                $op instanceof DrawImage => $p->drawImage($this->pdfImage($op), $op->x, $h - $op->y - $op->height, $op->width, $op->height),
                $op instanceof Clip => $this->wrapped($p, fn () => $p->withClipRect($op->x, $h - $op->y - $op->height, $op->width, $op->height, fn () => $this->drawOps($p, $h, $op->ops, $inherited))),
                $op instanceof Opacity => $this->drawOpacity($p, $h, $op, $inherited),
                $op instanceof Link => $p->addExternalLink($op->x, $h - $op->y - $op->height, $op->width, $op->height, $op->uri),
                default => throw new \LogicException('Unsupported op ' . $op::class),
            };
        }
    }

    private function drawText(PdfPage $p, float $h, TextRun $t, float $inherited): void
    {
        if ($t->text === '' || $t->color->isTransparent()) {
            return;
        }
        $font = $this->pdfFont($t->font);
        [$r, $g, $b] = $t->color->toUnitRgb();
        $this->withAlpha($p, $t->color, $inherited, fn () => $p->showEmbeddedText($t->text, $t->x, $h - $t->baseline, $font, $t->size, $r, $g, $b, $t->letterSpacing));
    }

    private function drawRect(PdfPage $p, float $h, Rect $o, float $inherited): void
    {
        $y = $h - $o->y - $o->height;
        if ($o->fill !== null && !$o->fill->isTransparent()) {
            [$r, $g, $b] = $o->fill->toUnitRgb();
            $this->withAlpha($p, $o->fill, $inherited, fn () => $p->fillRoundedRect($o->x, $y, $o->width, $o->height, $o->radius, $r, $g, $b));
        }
        if ($o->stroke !== null && !$o->stroke->isTransparent() && $o->strokeWidth > 0) {
            [$r, $g, $b] = $o->stroke->toUnitRgb();
            $this->withAlpha($p, $o->stroke, $inherited, fn () => $p->strokeRoundedRect($o->x, $y, $o->width, $o->height, $o->radius, $o->strokeWidth, $r, $g, $b));
        }
    }

    private function drawLine(PdfPage $p, float $h, Line $l, float $inherited): void
    {
        if ($l->color->isTransparent()) {
            return;
        }
        [$r, $g, $b] = $l->color->toUnitRgb();
        $this->withAlpha($p, $l->color, $inherited, fn () => $l->dashed
            ? $p->strokeDashedLine($l->x1, $h - $l->y1, $l->x2, $h - $l->y2, $l->width, $r, $g, $b, [3 * $l->width, 2 * $l->width])
            : $p->strokeLine($l->x1, $h - $l->y1, $l->x2, $h - $l->y2, $l->width, $r, $g, $b));
    }

    private function drawOpacity(PdfPage $p, float $h, Opacity $op, float $inherited): void
    {
        if ($op->alpha <= 0.0) {
            return;
        }
        if ($op->alpha >= 1.0) {
            $this->drawOps($p, $h, $op->ops, $inherited);
            return;
        }
        // PDF /ca replaces rather than multiplies, so compose nested alphas ourselves.
        $effective = $inherited * $op->alpha;
        $this->wrapped($p, fn () => $p->withOpacity($effective, $effective, fn () => $this->drawOps($p, $h, $op->ops, $effective)));
    }

    private function withAlpha(PdfPage $p, Color $c, float $inherited, \Closure $draw): void
    {
        $effective = $c->alpha * $inherited;
        if ($effective === $inherited) {
            $draw();
            return;
        }
        $this->wrapped($p, fn () => $p->withOpacity($effective, $effective, $draw));
    }

    private function wrapped(PdfPage $p, \Closure $wrapper): void
    {
        $wrapper();
        $this->invalidateTextState($p);
    }

    /**
     * php-pdf's ContentStream caches the last text fill colour and char spacing and skips
     * `rg`/`Tc` when unchanged, but our q...Q wrappers discard that graphics state. Poison
     * the cache (NAN never === anything) so the next text run re-emits both.
     */
    private function invalidateTextState(PdfPage $p): void
    {
        $stream = $p->rawContentStream();
        self::guardTextStateProperties($stream, self::TEXT_STATE_PROPERTIES);
        \Closure::bind(function (): void {
            $this->lastFillR = $this->lastFillG = $this->lastFillB = NAN;
            $this->lastCharSpacing = NAN;
        }, $stream, $stream::class)();
    }

    /**
     * @internal public only so tests can exercise it against a fake object.
     * @param list<string> $properties
     */
    public static function guardTextStateProperties(object $stream, array $properties): void
    {
        foreach ($properties as $property) {
            if (!property_exists($stream, $property)) {
                throw new \LogicException('papercut/core: dskripchenko/php-pdf internals changed (ContentStream text-state cache); pin dskripchenko/php-pdf to a compatible 1.x version or upgrade papercut/core.');
            }
        }
    }

    private function pdfFont(FontHandle $font): PdfFont
    {
        $inner = $font instanceof CachedFont ? $font->inner() : $font;
        if (!$inner instanceof PhpPdfFont) {
            throw new \LogicException('PhpPdfBackend can only draw fonts it loaded; got ' . $inner::class);
        }
        return $inner->pdfFont();
    }

    private function pdfImage(DrawImage $op): PdfImage
    {
        if (!$op->image instanceof PhpPdfImage) {
            throw new \LogicException('PhpPdfBackend can only draw images it loaded; got ' . $op->image::class);
        }
        return $op->image->pdfImage();
    }
}
