<?php

declare(strict_types=1);

namespace Papercut\Html;

use Papercut\Document;
use Papercut\Export\HtmlOptions;

/** The fixed stylesheet shared by every HTML export; only the page box and @font-face rules vary. */
final class Stylesheet
{
    public static function css(Document $doc, HtmlOptions $o, string $fontFaces): string
    {
        $page = $doc->pages()[0] ?? null;
        $w = $page === null ? 595.28 : $page->width();
        $h = $page === null ? 841.89 : $page->height();
        $m = $page === null ? '0pt' : self::margins($page->margins());
        $pageRule = $o->fullPage() ? '@page{size:' . Css::pt($w) . ' ' . Css::pt($h) . ';margin:' . $m . '}' : null;

        if ($pageRule !== null && $page !== null && $page->mirrorsMargins()) {
            $e = $page->margins();
            $pageRule .= '@page :left{margin:' . Css::pt($e->top->points) . ' ' . Css::pt($e->left->points) . ' ' . Css::pt($e->bottom->points) . ' ' . Css::pt($e->right->points) . '}';
        }

        return $fontFaces . implode("\n", [
            '.papercut{background:#e5e7eb;padding:24px 0}',
            '.papercut *,.papercut *::before,.papercut *::after{box-sizing:border-box}',
            '.papercut p,.papercut h1,.papercut h2,.papercut h3,.papercut h4{margin:0;font:inherit;overflow-wrap:anywhere}',
            '.papercut a{color:inherit;text-decoration:inherit}',
            '.papercut img{display:block;max-width:100%}',
            '.papercut table{border-collapse:collapse;table-layout:fixed;width:100%}',
            '.papercut td,.papercut th{vertical-align:top;text-align:inherit;font-weight:inherit}',
            '.papercut .pc-page{position:relative;z-index:0;margin:0 auto 24px;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.15)}',
            '.papercut .pc-header,.papercut .pc-body,.papercut .pc-footer,.papercut .pc-background{display:flex;flex-direction:column;align-items:stretch}',
            '.papercut .pc-background{position:absolute;inset:0;z-index:-1}',
            '.papercut .pc-break{break-before:page}',
            ...($pageRule === null ? [] : [$pageRule]),
            '@media print{.papercut{background:none;padding:0}.papercut .pc-page{max-width:none;min-height:0;margin:0;padding:0;box-shadow:none;break-after:page}.papercut tr{break-inside:avoid}}',
        ]);
    }

    private static function margins(\Papercut\Value\Edges $e): string
    {
        return Css::pt($e->top->points) . ' ' . Css::pt($e->right->points) . ' ' . Css::pt($e->bottom->points) . ' ' . Css::pt($e->left->points);
    }
}
