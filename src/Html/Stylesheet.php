<?php

declare(strict_types=1);

namespace Quoin\Html;

use Quoin\Document;
use Quoin\Export\HtmlOptions;

/**
 * The fixed stylesheet shared by every HTML export; only the page box and @font-face rules vary.
 *
 * @internal Engine detail, not public API. It may change in any release.
 */
final class Stylesheet
{
    public static function css(Document $doc, HtmlOptions $o, string $fontFaces, bool $math = false): string
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
            '.quoin{background:#e5e7eb;padding:24px 0}',
            '.quoin *,.quoin *::before,.quoin *::after{box-sizing:border-box}',
            '.quoin p,.quoin h1,.quoin h2,.quoin h3,.quoin h4{margin:0;font:inherit;overflow-wrap:anywhere}',
            '.quoin a{color:inherit;text-decoration:inherit}',
            '.quoin img{display:block;max-width:100%}',
            '.quoin table{border-collapse:collapse;table-layout:fixed;width:100%}',
            '.quoin td,.quoin th{vertical-align:top;text-align:inherit;font-weight:inherit}',
            '.quoin .pc-page{position:relative;z-index:0;margin:0 auto 24px;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.15)}',
            '.quoin .pc-header,.quoin .pc-body,.quoin .pc-footer,.quoin .pc-background{display:flex;flex-direction:column;align-items:stretch}',
            '.quoin .pc-background{position:absolute;inset:0;z-index:-1}',
            '.quoin .pc-break{break-before:page}',
            '.quoin .pc-column-break{break-before:column}',
            ...($math ? ['.quoin math{font-family:"Quoin Math",math}', '.quoin math[display=block]{width:fit-content;max-width:100%}'] : []),
            ...($pageRule === null ? [] : [$pageRule]),
            '@media print{.quoin{background:none;padding:0}.quoin .pc-page{max-width:none;min-height:0;margin:0;padding:0;box-shadow:none;break-after:page}.quoin tr{break-inside:avoid}}',
        ]);
    }

    private static function margins(\Quoin\Value\Edges $e): string
    {
        return Css::pt($e->top->points) . ' ' . Css::pt($e->right->points) . ' ' . Css::pt($e->bottom->points) . ' ' . Css::pt($e->left->points);
    }
}
