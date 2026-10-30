<?php

declare(strict_types=1);

namespace Papercut\Layout;

use Papercut\{Column, Component, Document, Node, OverflowMode, Page, PageVariant, View};
use Papercut\Value\{Length, Percent};
use Papercut\Exception\LayoutOverflow;
use Papercut\Style\ComputedStyle;

/**
 * Flows each Page template's body across physical pages between its header
 * and footer, with its background behind. Header, footer and background
 * fragments are laid out once per template (once per variant) and reused on the pages that use them.
 */
final class Paginator
{
    private const int MAX_PAGES = 10_000;

    public function __construct(private readonly LayoutEngine $engine, private readonly LayoutContext $ctx)
    {
    }

    /** @return list<PlacedPage> */
    public function paginate(Document $doc): array
    {
        $out = [];
        $templates = $doc->pages();
        foreach ($templates as $i => $page) {
            // Overflow paths start at the template: "page > body > image[1]", or "page[2] > ..." with several.
            $name = count($templates) > 1 ? sprintf('page[%d]', $i) : 'page';
            $this->ctx->pushPath($name);
            try {
                $this->paginatePage($page, $name, $out);
            } finally {
                $this->ctx->popPath();
            }
        }
        return $out;
    }

    /** @param list<PlacedPage> $out */
    private function paginatePage(Page $page, string $name, array &$out): void
    {
        $page->checkMargins($name);
        $root = $this->ctx->rootStyle();
        $w = $page->width();
        $h = $page->height();
        $m = $page->margins();
        $top = $m->top->points;
        $contentWidth = $w - $m->left->points - $m->right->points;
        $contentHeight = $h - $top - $m->bottom->points;
        $runStart = count($out);

        /** @var array{headers: array<string, ?Fragment>, footers: array<string, ?Fragment>, bands: array<string, array{header: ?Fragment, footer: ?Fragment, headerSpace: float, footerSpace: float}>} $cache each variant laid out once per run */
        $cache = ['headers' => [], 'footers' => [], 'bands' => []];
        $bandsFor = function (PageVariant $hv, PageVariant $fv, bool $report = true) use ($page, $root, $contentWidth, $contentHeight, $name, &$cache): array {
            return $this->bands($page, $root, $contentWidth, $contentHeight, $name, $cache, $hv, $fv, $report);
        };

        // Page 1's band first, so its no-room error or warning comes before anything the layers might report.
        $bandsFor($page->headerVariantOn(true, $runStart + 1), $page->footerVariantOn(true, $runStart + 1));

        $background = null;
        if ($page->backgroundNodes() !== []) {
            $background = $this->withoutBookmarks(fn (): ?Fragment => $this->engine->layout(Column::make(...$page->backgroundNodes()), $root, new Constraints($w, null, true, $h), pathName: 'background')->fragment);
        }

        /** @var list<array{x: float, y: float, fragment: Fragment}> $layers */
        $layers = [];
        $template = $page->templatePage();
        if ($template !== null) {
            $fragment = $this->engine->layout($template, $root, new Constraints($w, null, true, $h), pathName: 'template')->fragment;
            if ($fragment !== null) {
                $layers[] = ['x' => 0.0, 'y' => 0.0, 'fragment' => $fragment];
            }
        }
        if ($background !== null) {
            $layers[] = ['x' => 0.0, 'y' => 0.0, 'fragment' => $background];
        }
        $overlay = $this->overlay($page, $root, $w, $h);

        $body = Column::make(...$page->bodyNodes());
        $token = null;
        while (true) {
            $number = count($out) + 1;
            $firstOfRun = count($out) === $runStart;
            $band = $bandsFor($page->headerVariantOn($firstOfRun, $number), $page->footerVariantOn($firstOfRun, $number));
            $left = $page->marginsOn($number)->left->points;
            $bodyY = $top + $band['headerSpace'];
            $bodyHeight = $contentHeight - $band['headerSpace'] - $band['footerSpace'];
            $header = $band['header'];
            $footer = $band['footer'];
            $emit = function (Fragment $body) use (&$out, $w, $h, $layers, $overlay, $header, $footer, $left, $top, $bodyY, $m): void {
                if (count($out) >= self::MAX_PAGES) {
                    throw new LayoutOverflow('document', sprintf('more than %d pages', self::MAX_PAGES));
                }
                $before = $header === null ? $layers : [...$layers, ['x' => $left, 'y' => $top, 'fragment' => $header]];
                $after = $footer === null ? [] : [['x' => $left, 'y' => $h - $m->bottom->points - $footer->height, 'fragment' => $footer]];
                $out[] = new PlacedPage($w, $h, [...$before, ['x' => $left, 'y' => $bodyY, 'fragment' => $body], ...$after, ...$overlay]);
            };

            // A page shorter than a later one (a tall first-page or even-page header) may be what is in the way
            // of a block. Look at the next two pages, which cover the odd/even cycle, without reporting: they
            // may never be rendered.
            $nextBodyHeight = 0.0;
            foreach ([1, 2] as $ahead) {
                $band = $bandsFor($page->headerVariantOn(false, $number + $ahead), $page->footerVariantOn(false, $number + $ahead), false);
                $nextBodyHeight = max($nextBodyHeight, $contentHeight - $band['headerSpace'] - $band['footerSpace']);
            }
            $shorter = $nextBodyHeight > $bodyHeight;
            // Clip mode would clip a block in place on such a page; try it unclipped first, so the block can
            // move to the taller page as in Throw mode.
            $trial = $shorter && $this->ctx->overflow === OverflowMode::Clip;
            $res = $this->layoutBody($body, $root, $contentWidth, $bodyHeight, $token, clipInPlace: !$trial);
            if ($shorter && !self::progressed($res, $token)) {
                if ($this->fits($body, $root, $contentWidth, $nextBodyHeight, $token)) {
                    // Leave this page's body empty and retry on the next one, reporting its band now that it
                    // will be rendered.
                    $bandsFor($page->headerVariantOn(false, $number + 1), $page->footerVariantOn(false, $number + 1));
                    $emit(new Fragment($contentWidth, 0.0));
                    continue;
                }
                if ($trial) {
                    // The block fits on no page: clip it here, as on any other page.
                    $res = $this->layoutBody($body, $root, $contentWidth, $bodyHeight, $token, clipInPlace: true);
                }
            }
            if ($res->status === Status::Complete && $res->fragment !== null) {
                $emit($res->fragment);
                return;
            }
            if ($res->status === Status::Partial && $res->fragment !== null && $res->token != $token) {
                $emit($res->fragment);
                $token = $res->token;
                continue;
            }

            // DoesNotFit on a fresh page, or a Partial that made no progress.
            $path = $this->ctx->lastFailurePath ?? $name . ' > body';
            if ($this->ctx->overflow === OverflowMode::Throw) {
                throw new LayoutOverflow($path, 'content is taller than the page body');
            }
            // Last resort: the engine clips single overflowing nodes, so this is only reached when the body
            // itself cannot progress.
            $res = $this->engine->layout($body, $root, new Constraints($contentWidth, null, true), $token, pathName: 'body');
            if ($res->fragment !== null) {
                $clip = new Fragment($contentWidth, $bodyHeight);
                $clip->clip = [0.0, 0.0, $contentWidth, $bodyHeight];
                $clip->addChild(0.0, 0.0, $res->fragment);
                $emit($clip);
            }
            $this->ctx->warn(sprintf('Content clipped: "%s" made no progress; the rest of this page template was clipped and any remaining content dropped.', $path), withPath: false);
            return;
        }
    }

    /**
     * The header and footer for one pair of variants, with the room they take, laid out once per run.
     * When they leave no room for content, Throw mode throws and Clip mode clips them and warns;
     * $report false is a look-ahead at a page that may never be rendered, so it neither throws nor
     * warns, and does not remember the result.
     *
     * @param array{headers: array<string, ?Fragment>, footers: array<string, ?Fragment>, bands: array<string, array{header: ?Fragment, footer: ?Fragment, headerSpace: float, footerSpace: float}>} $cache
     * @return array{header: ?Fragment, footer: ?Fragment, headerSpace: float, footerSpace: float}
     */
    private function bands(Page $page, ComputedStyle $root, float $contentWidth, float $contentHeight, string $name, array &$cache, PageVariant $hv, PageVariant $fv, bool $report): array
    {
        $key = $hv->value . '|' . $fv->value;
        if (isset($cache['bands'][$key])) {
            return $cache['bands'][$key];
        }
        $held = false;
        if (array_key_exists($hv->value, $cache['headers'])) {
            $header = $cache['headers'][$hv->value];
        } else {
            [$header, $keep] = $this->bandSlot($page->headerNodesOf($hv), $root, $contentWidth, self::slotName('header', $hv), $report);
            $keep ? $cache['headers'][$hv->value] = $header : $held = true;
        }
        if (array_key_exists($fv->value, $cache['footers'])) {
            $footer = $cache['footers'][$fv->value];
        } else {
            [$footer, $keep] = $this->bandSlot($page->footerNodesOf($fv), $root, $contentWidth, self::slotName('footer', $fv), $report);
            $keep ? $cache['footers'][$fv->value] = $footer : $held = true;
        }
        $headerSpace = $header === null ? 0.0 : $header->height + $page->headerGapLength()->points;
        $footerSpace = $footer === null ? 0.0 : $footer->height + $page->footerGapLength()->points;

        $noRoom = $contentHeight - $headerSpace - $footerSpace <= 0.0;
        if ($noRoom) {
            $label = $name . match (true) {
                $hv === PageVariant::First || $fv === PageVariant::First => ' (first page)',
                $hv === PageVariant::Even || $fv === PageVariant::Even => ' (even page)',
                default => '',
            };
            if ($report && ($this->ctx->overflow === OverflowMode::Throw || $contentHeight <= 0.0)) {
                throw new LayoutOverflow($label, 'header and footer leave no room for content');
            }
            // Clip mode: header and footer (with their gaps) each keep at most a third of the page body.
            $limit = $contentHeight / 3;
            if ($header !== null && $headerSpace > $limit) {
                $header = self::clipTo($header, max(0.0, $limit - $page->headerGapLength()->points));
                $headerSpace = $limit;
            }
            if ($footer !== null && $footerSpace > $limit) {
                $footer = self::clipTo($footer, max(0.0, $limit - $page->footerGapLength()->points));
                $footerSpace = $limit;
            }
            if ($report) {
                $this->ctx->warn(sprintf('Content clipped: header and footer leave no room for content on "%s"; each was clipped to a third of the page body.', $label), withPath: false);
            }
        }
        $band = ['header' => $header, 'footer' => $footer, 'headerSpace' => $headerSpace, 'footerSpace' => $footerSpace];
        if (($report || !$noRoom) && !$held) {
            $cache['bands'][$key] = $band;
        }
        return $band;
    }

    private function layoutBody(Node|Component $body, ComputedStyle $root, float $contentWidth, float $bodyHeight, ?BreakToken $token, bool $clipInPlace): LayoutResult
    {
        $this->ctx->setLastFailurePath(null);
        $was = $this->ctx->clipInPlace;
        $this->ctx->clipInPlace = $clipInPlace;
        try {
            return $this->engine->layout($body, $root, new Constraints($contentWidth, $bodyHeight, true), $token, pathName: 'body');
        } finally {
            $this->ctx->clipInPlace = $was;
        }
    }

    private static function progressed(LayoutResult $res, ?BreakToken $token): bool
    {
        if ($res->status === Status::Complete) {
            return $res->fragment !== null;
        }
        return $res->status === Status::Partial && $res->fragment !== null && $res->token != $token;
    }

    /** Whether the rest of the body makes progress in a body of this height, without clipping anything in place. */
    private function fits(Node|Component $body, ComputedStyle $root, float $contentWidth, float $bodyHeight, ?BreakToken $token): bool
    {
        $failure = $this->ctx->lastFailurePath;
        $res = $this->layoutBody($body, $root, $contentWidth, $bodyHeight, $token, clipInPlace: false);
        $this->ctx->setLastFailurePath($failure);
        return self::progressed($res, $token);
    }

    private static function slotName(string $band, PageVariant $v): string
    {
        return match ($v) {
            PageVariant::Default => $band,
            PageVariant::First => 'first-' . $band,
            PageVariant::Even => 'even-' . $band,
        };
    }

    private static function clipTo(Fragment $f, float $height): Fragment
    {
        $clip = new Fragment($f->width, $height);
        $clip->clip = [0.0, 0.0, $f->width, $height];
        $clip->addChild(0.0, 0.0, $f);
        return $clip;
    }

    /**
     * Each place, laid out once and put with its anchor at its point on the page.
     *
     * @return list<array{x: float, y: float, fragment: Fragment}>
     */
    private function overlay(Page $page, ComputedStyle $root, float $w, float $h): array
    {
        $layers = [];
        $this->ctx->pushPath('overlay');
        try {
            foreach ($page->overlayPlaces() as $i => $place) {
                $box = View::make(...$place->children());
                $given = $place->boxWidth();
                $width = $given === null
                    ? min($w, $this->engine->intrinsic($box, $root)['max'])
                    : max(0.0, self::resolve($given, $w));
                $fragment = $this->withoutBookmarks(fn (): ?Fragment => $this->engine->layout($box, $root, new Constraints($width, null, true), pathName: sprintf('place[%d]', $i))->fragment);
                if ($fragment === null) {
                    continue;
                }
                $anchor = $place->anchorPoint();
                $layers[] = [
                    'x' => self::resolve($place->x(), $w) - $anchor->across() * $fragment->width,
                    'y' => self::resolve($place->y(), $h) - $anchor->down() * $fragment->height,
                    'fragment' => $fragment,
                ];
            }
        } finally {
            $this->ctx->popPath();
        }

        return $layers;
    }

    private static function resolve(Length|Percent $v, float $basis): float
    {
        return $v instanceof Percent ? $v->resolve($basis) : $v->points;
    }

    /**
     * A header or footer band. Without $report it is laid out quietly, as it may never be rendered;
     * the second value is false when that held back a report or throw, so it is not to be kept.
     *
     * @param list<\Papercut\Node|\Papercut\Component> $nodes
     * @return array{?Fragment, bool}
     */
    private function bandSlot(array $nodes, ComputedStyle $root, float $width, string $pathName, bool $report): array
    {
        if ($report) {
            return [$this->slot($nodes, $root, $width, $pathName), true];
        }
        return $this->ctx->quietly(fn (): ?Fragment => $this->slot($nodes, $root, $width, $pathName));
    }

    /** @param list<\Papercut\Node|\Papercut\Component> $nodes */
    private function slot(array $nodes, ComputedStyle $root, float $width, string $pathName): ?Fragment
    {
        if ($nodes === []) {
            return null;
        }
        return $this->withoutBookmarks(fn (): ?Fragment => $this->engine->layout(Column::make(...$nodes), $root, new Constraints($width, null, true), pathName: $pathName)->fragment);
    }

    /**
     * Repeating bands would add the same bookmark on every page.
     *
     * @param \Closure(): ?Fragment $layout
     */
    private function withoutBookmarks(\Closure $layout): ?Fragment
    {
        $was = $this->ctx->bookmarks;
        $this->ctx->bookmarks = false;
        try {
            return $layout();
        } finally {
            $this->ctx->bookmarks = $was;
        }
    }
}
