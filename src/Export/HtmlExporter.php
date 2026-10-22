<?php

declare(strict_types=1);

namespace Papercut\Export;

use Papercut\Backend\DefaultBackend;
use Papercut\Document;
use Papercut\Html\{Css, FontFaces, FontRequests, FontStack, HtmlWriter, Stylesheet};
use Papercut\Style\StyleResolver;
use Papercut\Tree\TreeWalker;

final class HtmlExporter implements Exporter
{
    public function export(Document $doc, ExportOptions $options): Output
    {
        $options = HtmlOptions::from($options);
        if ($options->fontMode() === FontMode::Link && $options->fontUrl() === null) {
            throw new \InvalidArgumentException('FontMode::Link needs a fontUrl callback');
        }
        if ($options->fontMode() === FontMode::Sources && $options->fontSources() === []) {
            throw new \InvalidArgumentException('FontMode::Sources needs at least one font source');
        }
        $styles = new StyleResolver($options->theme());
        $fidelity = new Fidelity('html', $options);
        NoteReporter::report($doc, $styles, $fidelity);
        $writer = new HtmlWriter($doc, $fidelity, $options, DefaultBackend::make(false));
        (new TreeWalker($styles))->walk($doc, $writer);

        $meta = $doc->meta();
        $root = $styles->root();
        $families = array_values(array_unique([...$writer->usedFamilies(), $root->fontFamily, ...$options->fonts()->fallbacks]));
        $stack = null;
        if ($options->fontMode() === FontMode::Sources) {
            $stack = FontStack::resolve(FontRequests::collect($writer->fontUsage(), $root->fontFamily, $options->fonts()), $options->fontSources(), $fidelity);
            // Full pages link from <head>; a fragment has no head, so its @imports lead the <style> block.
            $fontCss = ($options->fullPage() ? '' : $stack->importCss()) . $stack->faceCss();
        } else {
            $fontCss = FontFaces::css($families, $options->fonts(), $options->fontMode(), $options->fontUrl());
        }
        $css = Stylesheet::css($doc, $options, $fontCss);
        $lang = $meta->lang !== null ? ' lang="' . HtmlWriter::esc($meta->lang) . '"' : '';
        $rootDiv = '<div class="papercut"' . $lang . ' style="' . HtmlWriter::esc(Css::attr(Css::text($root, null, $options->fonts()->fallbacks))) . '">' . $writer->html() . '</div>';
        $style = '<style>' . $css . '</style>';

        if (!$options->fullPage()) {
            return new Output($style . $rootDiv, 'text/html; charset=UTF-8', 'html');
        }

        $head = '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . HtmlWriter::esc($meta->title ?? '') . '</title>';
        foreach (['author' => $meta->author, 'description' => $meta->subject, 'keywords' => $meta->keywords] as $name => $value) {
            if ($value !== null) {
                $head .= '<meta name="' . $name . '" content="' . HtmlWriter::esc($value) . '">';
            }
        }

        foreach ($stack?->links() ?? [] as $link) {
            $head .= '<link rel="stylesheet" href="' . HtmlWriter::esc($link) . '">';
        }

        return new Output(
            '<!DOCTYPE html><html lang="' . HtmlWriter::esc($meta->lang ?? 'en') . '"><head>' . $head . $style . '</head><body>' . $rootDiv . '</body></html>',
            'text/html; charset=UTF-8',
            'html',
        );
    }

    public function defaultOptions(): ExportOptions
    {
        return new HtmlOptions();
    }
}
