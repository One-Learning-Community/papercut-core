<?php

declare(strict_types=1);

namespace Papercut\Layout;

use Papercut\{Column, Document, OverflowMode, Page, View};
use Papercut\Value\{Length, Percent};
use Papercut\Exception\LayoutOverflow;
use Papercut\Style\ComputedStyle;

/**
 * Flows each Page template's body across physical pages between its header
 * and footer, with its background behind. Header, footer and background
 * fragments are laid out once per template and reused on every page.
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
        $left = $m->left->points;
        $top = $m->top->points;
        $contentWidth = $w - $left - $m->right->points;
        $contentHeight = $h - $top - $m->bottom->points;

        $header = $this->slot($page->headerNodes(), $root, $contentWidth, 'header');
        $footer = $this->slot($page->footerNodes(), $root, $contentWidth, 'footer');
        $headerSpace = $header === null ? 0.0 : $header->height + $page->headerGapLength()->points;
        $footerSpace = $footer === null ? 0.0 : $footer->height + $page->footerGapLength()->points;

        if ($contentHeight - $headerSpace - $footerSpace <= 0.0) {
            if ($this->ctx->overflow === OverflowMode::Throw || $contentHeight <= 0.0) {
                throw new LayoutOverflow($name, 'header and footer leave no room for content');
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
            $this->ctx->warn(sprintf('Content clipped: header and footer leave no room for content on "%s"; each was clipped to a third of the page body.', $name), withPath: false);
        }
        $bodyY = $top + $headerSpace;
        $bodyHeight = $contentHeight - $headerSpace - $footerSpace;

        $background = null;
        if ($page->backgroundNodes() !== []) {
            $background = $this->withoutBookmarks(fn (): ?Fragment => $this->engine->layout(Column::make(...$page->backgroundNodes()), $root, new Constraints($w, null, true, $h), pathName: 'background')->fragment);
        }

        /** @var list<array{x: float, y: float, fragment: Fragment}> $before */
        $before = [];
        $template = $page->templatePage();
        if ($template !== null) {
            $fragment = $this->engine->layout($template, $root, new Constraints($w, null, true, $h), pathName: 'template')->fragment;
            if ($fragment !== null) {
                $before[] = ['x' => 0.0, 'y' => 0.0, 'fragment' => $fragment];
            }
        }
        if ($background !== null) {
            $before[] = ['x' => 0.0, 'y' => 0.0, 'fragment' => $background];
        }
        if ($header !== null) {
            $before[] = ['x' => $left, 'y' => $top, 'fragment' => $header];
        }
        $after = $footer === null ? [] : [['x' => $left, 'y' => $h - $m->bottom->points - $footer->height, 'fragment' => $footer]];
        $after = [...$after, ...$this->overlay($page, $root, $w, $h)];
        $emit = function (Fragment $body) use (&$out, $w, $h, $before, $after, $left, $bodyY): void {
            if (count($out) >= self::MAX_PAGES) {
                throw new LayoutOverflow('document', sprintf('more than %d pages', self::MAX_PAGES));
            }
            $out[] = new PlacedPage($w, $h, [...$before, ['x' => $left, 'y' => $bodyY, 'fragment' => $body], ...$after]);
        };

        $body = Column::make(...$page->bodyNodes());
        $token = null;
        while (true) {
            $this->ctx->setLastFailurePath(null);
            $res = $this->engine->layout($body, $root, new Constraints($contentWidth, $bodyHeight, true), $token, pathName: 'body');
            if ($res->status === Status::Complete && $res->fragment !== null) {
                $emit($res->fragment);
                return;
            }
            if ($res->status === Status::Partial) {
                assert($res->token !== null, 'LayoutResult::partial() always carries a token');
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
            // Last resort: the engine clips single overflowing nodes, so this is only reached when the body itself cannot progress.
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
