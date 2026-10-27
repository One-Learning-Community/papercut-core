<?php

declare(strict_types=1);

namespace Papercut\Export;

use Papercut\Document;
use Papercut\Layout\{LayoutContext, LayoutEngine, Paginator};
use Papercut\Paint\Painter;
use Papercut\Style\StyleResolver;
use Papercut\Text\FontRegistry;

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
        NoteReporter::report($doc, new StyleResolver($options->theme()), new Fidelity('pdf', $options));
        $ctx = new LayoutContext(
            FontRegistry::fromSet($fonts, $backend),
            $options->theme(),
            $backend,
            $options->overflow(),
            $options->onWarning(),
            $fonts->fallbacks,
        );
        $ctx->bookmarks = $options->bookmarks();
        $placed = (new Paginator(new LayoutEngine($ctx), $ctx))->paginate($doc);

        $meta = $doc->meta()->withEncryption($options->encryption());

        return new Output($backend->render($meta, Painter::paint($placed)), 'application/pdf', 'pdf');
    }

    public function defaultOptions(): ExportOptions
    {
        return new PdfOptions();
    }
}
