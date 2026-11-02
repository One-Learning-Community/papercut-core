<?php

declare(strict_types=1);

namespace Papercut\Export;

use Papercut\Document;
use Papercut\Exception\UnsupportedFeature;
use Papercut\Layout\{LayoutContext, LayoutEngine, Paginator};
use Papercut\Paint\{Clip, Op, Opacity, PageDisplayList, Painter, TextRun};
use Papercut\Style\PageThemes;
use Papercut\Text\FontRegistry;
use Papercut\Toc\TocIndex;

/** @internal Part of Papercut's engine, not its public API. It may change in any release. */
final class PdfExporter implements Exporter
{
    /**
     * Lays out, paints and renders the document to PDF bytes. The font registry is built
     * on the same backend instance that renders.
     */
    public function export(Document $doc, ExportOptions $options): Output
    {
        $options = PdfOptions::from($options);
        $backend = $options->backend();
        $fonts = $options->fonts();
        $themes = PageThemes::for($options->theme(), $doc);
        NoteReporter::report($doc, $themes, new Fidelity('pdf', $options));
        $ctx = new LayoutContext(
            FontRegistry::fromSet($fonts, $backend),
            $themes->document(),
            $backend,
            $options->overflow(),
            $options->onWarning(),
            $fonts->fallbacks,
        );
        $ctx->themes = $themes;
        $ctx->bookmarks = $options->bookmarks();
        $ctx->strict = $options->strict();
        $ctx->toc = TocIndex::collect($doc, $themes);
        $placed = (new Paginator(new LayoutEngine($ctx), $ctx))->paginate($doc);
        $pages = Painter::paint($placed);
        if ($options->strict()) {
            self::requireGlyphs($pages);
        }

        $meta = $doc->meta()->withEncryption($options->encryption());

        return new Output($backend->render($meta, $pages), 'application/pdf', 'pdf');
    }

    /**
     * Strict mode: text on a page with characters its font has no glyph for (they print as empty boxes) throws.
     *
     * @param list<PageDisplayList> $pages
     * @throws UnsupportedFeature
     */
    private static function requireGlyphs(array $pages): void
    {
        foreach ($pages as $i => $page) {
            foreach (self::textRuns($page->ops) as $run) {
                if ($run->font->covers($run->text)) {
                    continue;
                }
                $missing = implode('', array_filter(
                    array_unique(mb_str_split($run->text, 1, 'UTF-8')),
                    static fn (string $ch): bool => !$run->font->covers($ch),
                ));
                throw UnsupportedFeature::from(new FidelityReport(
                    FidelityLevel::Dropped,
                    'pdf',
                    sprintf('characters no font has ("%s"; register a fallback font that has them)', $missing),
                    sprintf('page %d', $i + 1),
                ));
            }
        }
    }

    /**
     * @param list<Op> $ops
     * @return iterable<TextRun>
     */
    private static function textRuns(array $ops): iterable
    {
        foreach ($ops as $op) {
            if ($op instanceof TextRun) {
                yield $op;
            } elseif ($op instanceof Clip || $op instanceof Opacity) {
                yield from self::textRuns($op->ops);
            }
        }
    }

    public function defaultOptions(): ExportOptions
    {
        return new PdfOptions();
    }
}
