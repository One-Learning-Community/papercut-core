<?php

declare(strict_types=1);

namespace Papercut\Paint;

use Papercut\Layout\{Fragment, PlacedPage};
use Papercut\Style\TextAlign;

/**
 * Flattens placed fragment trees into absolute-coordinate display lists,
 * wrapping opacity and clip groups and resolving page-number slots.
 *
 * @internal Part of the display list between layout and the PDF writer. It may change in any release.
 */
final class Painter
{
    private function __construct()
    {
    }

    /**
     * @param list<PlacedPage> $pages
     * @return list<PageDisplayList>
     */
    public static function paint(array $pages): array
    {
        $total = count($pages);
        $anchors = self::anchorPages($pages);
        $out = [];
        foreach ($pages as $i => $page) {
            $ops = [];
            foreach ($page->layers as $layer) {
                array_push($ops, ...self::flatten($layer['fragment'], $layer['x'], $layer['y'], $i + 1, $total, $anchors));
            }
            $out[] = new PageDisplayList($page->width, $page->height, $ops);
        }
        return $out;
    }

    /**
     * The 1-based page of the first occurrence of each anchor. A repeated table header row lays its
     * content out on every page, so later occurrences are ignored.
     *
     * @param list<PlacedPage> $pages
     * @return array<string, int>
     */
    public static function anchorPages(array $pages): array
    {
        $found = [];
        foreach ($pages as $i => $page) {
            foreach ($page->layers as $layer) {
                self::collectAnchors($layer['fragment'], $i + 1, $found);
            }
        }
        return $found;
    }

    /** @param array<string, int> $found */
    private static function collectAnchors(Fragment $f, int $page, array &$found): void
    {
        self::collectOpAnchors($f->ops(), $page, $found);
        foreach ($f->children() as $c) {
            self::collectAnchors($c['fragment'], $page, $found);
        }
    }

    /**
     * @param list<Op> $ops
     * @param array<string, int> $found
     */
    private static function collectOpAnchors(array $ops, int $page, array &$found): void
    {
        foreach ($ops as $op) {
            if ($op instanceof Anchor) {
                $found[$op->name] ??= $page;
            } elseif ($op instanceof Clip || $op instanceof Opacity) {
                self::collectOpAnchors($op->ops, $page, $found);
            }
        }
    }

    /**
     * @param array<string, int> $anchors
     * @return list<Op>
     */
    private static function flatten(Fragment $f, float $x, float $y, int $page, int $total, array $anchors): array
    {
        $ops = [];
        foreach ($f->ops() as $op) {
            $ops[] = self::translate($op, $x, $y);
        }
        foreach ($f->slots() as $slot) {
            if ($slot instanceof TocSlot) {
                $target = $anchors[$slot->destination] ?? null;
                if ($target !== null) {
                    array_push($ops, ...self::resolveTocSlot($slot, $x, $y, $target));
                }
                continue;
            }
            $run = self::resolveSlot($slot, $x, $y, $page, $total);
            if ($run !== null) {
                $ops[] = $run;
            }
        }
        foreach ($f->children() as $c) {
            array_push($ops, ...self::flatten($c['fragment'], $x + $c['x'], $y + $c['y'], $page, $total, $anchors));
        }
        if ($f->clip !== null && $ops !== []) {
            [$cx, $cy, $cw, $ch] = $f->clip;
            $ops = [new Clip($x + $cx, $y + $cy, $cw, $ch, $ops)];
        }
        if ($f->opacity !== null && $ops !== []) {
            $ops = [new Opacity($f->opacity, $ops)];
        }
        return $ops;
    }

    private static function translate(Op $op, float $dx, float $dy): Op
    {
        return match (true) {
            $op instanceof TextRun => new TextRun($op->x + $dx, $op->baseline + $dy, $op->text, $op->font, $op->size, $op->color, $op->letterSpacing),
            $op instanceof Rect => new Rect($op->x + $dx, $op->y + $dy, $op->width, $op->height, $op->fill, $op->stroke, $op->strokeWidth, $op->radius),
            $op instanceof Line => new Line($op->x1 + $dx, $op->y1 + $dy, $op->x2 + $dx, $op->y2 + $dy, $op->color, $op->width, $op->dashed),
            $op instanceof DrawImage => new DrawImage($op->x + $dx, $op->y + $dy, $op->width, $op->height, $op->image),
            $op instanceof DrawSvg => new DrawSvg($op->x + $dx, $op->y + $dy, $op->width, $op->height, $op->svg, $op->fonts),
            $op instanceof DrawPdfPage => new DrawPdfPage($op->x + $dx, $op->y + $dy, $op->width, $op->height, $op->page),
            $op instanceof Bookmark => new Bookmark($op->level, $op->title, $op->x + $dx, $op->y + $dy),
            $op instanceof Anchor => new Anchor($op->name, $op->x + $dx, $op->y + $dy),
            $op instanceof Link => new Link($op->x + $dx, $op->y + $dy, $op->width, $op->height, $op->uri),
            $op instanceof Clip => new Clip($op->x + $dx, $op->y + $dy, $op->width, $op->height, array_map(static fn (Op $o): Op => self::translate($o, $dx, $dy), $op->ops)),
            $op instanceof Opacity => new Opacity($op->alpha, array_map(static fn (Op $o): Op => self::translate($o, $dx, $dy), $op->ops)),
            default => throw new \LogicException(sprintf('Painter cannot translate %s.', $op::class)),
        };
    }

    private static function resolveSlot(PageNumberSlot $s, float $dx, float $dy, int $page, int $total): ?TextRun
    {
        $text = strtr($s->format, ['{page}' => (string) $page, '{total}' => (string) $total]);
        if (trim($text) === '') {
            return null;
        }
        $width = $s->font->advance($text, $s->size, $s->letterSpacing);
        $offset = match ($s->align) {
            TextAlign::Right => $s->width - $width,
            TextAlign::Center => ($s->width - $width) / 2,
            TextAlign::Left, TextAlign::Justify => 0.0,
        };
        return new TextRun($dx + $s->x + $offset, $dy + $s->baseline, $text, $s->font, $s->size, $s->color, $s->letterSpacing);
    }

    /**
     * The page number right-aligned in the slot's number box, dot leaders between the title and the
     * number, and a link over the whole entry.
     *
     * @return list<Op>
     */
    private static function resolveTocSlot(TocSlot $s, float $dx, float $dy, int $target): array
    {
        $gap = 3.0;
        $text = (string) $target;
        $numberWidth = $s->font->advance($text, $s->size, $s->letterSpacing);
        $numberLeft = $dx + $s->x + $s->width - $numberWidth;
        $baseline = $dy + $s->baseline;
        $ops = [new TextRun($numberLeft, $baseline, $text, $s->font, $s->size, $s->color, $s->letterSpacing)];
        if ($s->leaders) {
            $start = $dx + $s->leaderStart + $gap;
            $room = $numberLeft - $gap - $start;
            $dot = $s->font->advance('.', $s->size, $s->letterSpacing);
            $count = $dot > 0.0 ? (int) floor($room / $dot) : 0;
            if ($count > 0) {
                $ops[] = new TextRun($start, $baseline, str_repeat('.', $count), $s->font, $s->size, $s->color, $s->letterSpacing);
            }
        }
        $ops[] = new Link($dx, $dy, $s->boxWidth, $s->boxHeight, '#' . $s->destination);
        return $ops;
    }
}
