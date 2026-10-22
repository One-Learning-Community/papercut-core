<?php

declare(strict_types=1);

namespace Papercut\Paint;

use Papercut\Layout\{Fragment, PlacedPage};
use Papercut\Style\TextAlign;

/**
 * Flattens placed fragment trees into absolute-coordinate display lists,
 * wrapping opacity and clip groups and resolving page-number slots.
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
        $out = [];
        foreach ($pages as $i => $page) {
            $ops = [];
            foreach ($page->layers as $layer) {
                array_push($ops, ...self::flatten($layer['fragment'], $layer['x'], $layer['y'], $i + 1, $total));
            }
            $out[] = new PageDisplayList($page->width, $page->height, $ops);
        }
        return $out;
    }

    /** @return list<Op> */
    private static function flatten(Fragment $f, float $x, float $y, int $page, int $total): array
    {
        $ops = [];
        foreach ($f->ops() as $op) {
            $ops[] = self::translate($op, $x, $y);
        }
        foreach ($f->slots() as $slot) {
            $run = self::resolveSlot($slot, $x, $y, $page, $total);
            if ($run !== null) {
                $ops[] = $run;
            }
        }
        foreach ($f->children() as $c) {
            array_push($ops, ...self::flatten($c['fragment'], $x + $c['x'], $y + $c['y'], $page, $total));
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
}
