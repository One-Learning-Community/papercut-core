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
        /** @var ?InlineItem $space */
        $space = null;
        /** @var list<InlineItem> $chunk */
        $chunk = [];

        $place = static function () use (&$chunk, &$cur, &$w, &$space, &$lines, $max): void {
            if ($chunk === []) {
                return;
            }
            $cw = array_sum(array_map(static fn (InlineItem $i): float => $i->width, $chunk));
            $sw = $cur !== [] && $space !== null ? $space->width : 0.0;
            if ($cur !== [] && $w + $sw + $cw > $max + self::EPS) {
                $lines[] = [$cur, $w, false];
                $cur = [];
                $w = 0.0;
                $sw = 0.0;
                $space = null;
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
                if ($cur !== [] && $space !== null) {
                    $cur[] = $space;
                    $w += $sw;
                }
                foreach ($chunk as $it) {
                    $cur[] = $it;
                    $w += $it->width;
                }
            }
            $chunk = [];
            $space = null;
        };

        foreach ($items as $it) {
            if ($it->kind === 'break') {
                $place();
                $lines[] = [$cur, $w, true];
                $cur = [];
                $w = 0.0;
                $space = null;
            } elseif ($it->kind === 'space') {
                $place();
                $space = $it;
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
