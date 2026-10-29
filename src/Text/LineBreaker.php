<?php

declare(strict_types=1);

namespace Papercut\Text;

final class LineBreaker
{
    private const float EPS = 1e-9;

    /**
     * @param list<InlineItem> $items
     * @return list<Line>
     */
    public static function break(array $items, float $maxWidth): array
    {
        $max = $maxWidth <= 0.0 ? 1.0 : $maxWidth;
        /** @var list<array{list<InlineItem>, float, bool}> $lines */
        $lines = [];
        /** @var list<InlineItem> $cur */
        $cur = [];
        $w = 0.0;
        /** @var list<InlineItem> $gap the spaces and tabs between the last placed word and the chunk */
        $gap = [];
        /** @var list<InlineItem> $chunk */
        $chunk = [];
        // True from the start of a source line until its first word: spaces and tabs there are indentation and stay.
        $indent = true;

        $place = static function () use (&$chunk, &$cur, &$w, &$gap, &$lines, &$indent, $max): void {
            if ($chunk === []) {
                return;
            }
            $cw = array_sum(array_map(static fn (InlineItem $i): float => $i->width, $chunk));
            [$gapItems, $sw] = $cur !== [] ? self::resolveGap($gap, $w) : [[], 0.0];
            if ($cur !== [] && $w + $sw + $cw > $max + self::EPS) {
                $lines[] = [$cur, $w, false];
                $cur = [];
                $w = 0.0;
                $gapItems = [];
                $sw = 0.0;
            }
            if ($cur === [] && $cw > $max + self::EPS) {
                foreach (self::splitChunk($chunk, $max) as [$done, $dw]) {
                    if ($cur !== []) {
                        $lines[] = [$cur, $w, false];
                    }
                    $cur = $done;
                    $w = $dw;
                }
            } else {
                // Presence, not width: a space can measure <= 0 (negative letter spacing) and still separates words.
                foreach ($gapItems as $g) {
                    $cur[] = $g;
                }
                $w += $sw;
                foreach ($chunk as $it) {
                    $cur[] = $it;
                    $w += $it->width;
                }
            }
            $chunk = [];
            $gap = [];
            $indent = false;
        };

        foreach ($items as $it) {
            if ($it->kind === 'break') {
                $place();
                $lines[] = [$cur, $w, true];
                $cur = [];
                $w = 0.0;
                $gap = [];
                $indent = true;
            } elseif ($it->kind === 'space' || $it->kind === 'tab') {
                $place();
                if (!$indent) {
                    $gap[] = $it;
                    continue;
                }
                $pitch = $it->width;
                $sized = $it->kind === 'tab' ? $it->withWidth(self::tabAdvance($pitch, $w)) : $it;
                if ($cur !== [] && $w + $sized->width > $max + self::EPS) {
                    $lines[] = [$cur, $w, false];
                    $cur = [];
                    $w = 0.0;
                    $sized = $it->kind === 'tab' ? $it->withWidth(self::tabAdvance($pitch, 0.0)) : $it;
                }
                $it = $sized;
                $cur[] = $it;
                $w += $it->width;
            } else {
                $chunk[] = $it;
                if ($it->breakAfter) {
                    $place();
                }
            }
        }
        $place();
        if ($cur !== []) {
            $lines[] = [$cur, $w, false];
        }

        $out = [];
        $last = count($lines) - 1;
        foreach ($lines as $i => [$its, $width, $forced]) {
            $out[] = new Line($its, $width, $forced, $i === $last);
        }
        return $out;
    }

    /**
     * The gap's items with each tab sized for where it falls when the gap starts at $x, and the gap's total width.
     *
     * @param list<InlineItem> $gap
     * @return array{list<InlineItem>, float}
     */
    private static function resolveGap(array $gap, float $x): array
    {
        $out = [];
        $at = $x;
        foreach ($gap as $g) {
            $g = $g->kind === 'tab' ? $g->withWidth(self::tabAdvance($g->width, $at)) : $g;
            $out[] = $g;
            $at += $g->width;
        }
        return [$out, $at - $x];
    }

    /** Distance from $x to the next multiple of $pitch; a full pitch when $x is already on a stop. */
    private static function tabAdvance(float $pitch, float $x): float
    {
        if ($pitch <= 0.0) {
            return 0.0;
        }
        $rest = fmod($x, $pitch);
        $near = 1e-6 * $pitch;
        return $rest < $near || $pitch - $rest < $near ? $pitch : $pitch - $rest;
    }

    /**
     * Splits an over-wide chunk character by character. Each character is
     * measured once and widths are accumulated (linear in the chunk length);
     * each finished piece is then measured once for its exact width. Returns
     * lines as [items, width]; the last entry is the unfinished remainder line.
     *
     * @param list<InlineItem> $chunk
     * @return list<array{list<InlineItem>, float}>
     */
    private static function splitChunk(array $chunk, float $max): array
    {
        $lines = [];
        $cur = [];
        $w = 0.0;
        $piece = static fn (InlineItem $item, string $text, bool $breakAfter): InlineItem => $item->withText(
            $text,
            $item->font->advance($text, $item->size, $item->style->letterSpacing),
            $breakAfter,
        );
        foreach ($chunk as $item) {
            $buf = '';
            $bufW = 0.0;
            foreach (mb_str_split($item->text, 1, 'UTF-8') as $ch) {
                $chW = $item->font->advance($ch, $item->size, $item->style->letterSpacing);
                if ($w + $bufW + $chW > $max + self::EPS && ($buf !== '' || $cur !== [])) {
                    if ($buf !== '') {
                        $done = $piece($item, $buf, false);
                        $cur[] = $done;
                        $w += $done->width;
                    }
                    $lines[] = [$cur, $w];
                    $cur = [];
                    $w = 0.0;
                    $buf = $ch;
                    $bufW = $chW;
                } else {
                    $buf .= $ch;
                    $bufW += $chW;
                }
            }
            if ($buf !== '') {
                $done = $piece($item, $buf, $item->breakAfter);
                $cur[] = $done;
                $w += $done->width;
            }
        }
        $lines[] = [$cur, $w];
        return $lines;
    }
}
