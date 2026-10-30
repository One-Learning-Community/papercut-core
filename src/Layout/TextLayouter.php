<?php

declare(strict_types=1);

namespace Papercut\Layout;

use Papercut\{Node, Text};
use Papercut\Paint\{Line as LineOp, Link as LinkOp, Rect, TextRun};
use Papercut\Style\{ComputedStyle, TextAlign, WhiteSpace};
use Papercut\Text\{FontStyle, InlineItem, Line, LineBreaker};
use Papercut\Value\Length;

/**
 * Lays out a Text node as a stack of line boxes and fragments it between
 * lines, honouring min-lines as both orphans and widows. Box styles
 * (width, padding, border, background, opacity) apply via BoxGeometry with
 * sliced semantics; margins are the parent's job.
 */
final class TextLayouter implements Layouter
{
    /** @var \WeakMap<Node, array<string, array{style: ComputedStyle, lines: list<Line>, boxes: list<array{baseline: float, height: float}>}>> */
    private \WeakMap $lineCache;

    public function __construct()
    {
        $this->lineCache = new \WeakMap();
    }

    public function layout(Node $node, ComputedStyle $style, Constraints $c, ?BreakToken $token, LayoutEngine $engine): LayoutResult
    {
        $box = [
            'width' => BoxGeometry::borderBoxWidth($style, $c->width),
            'content' => BoxGeometry::contentWidth($style, $c->width),
            'first' => ($token->index ?? 0) <= 0,
            'size' => null,
        ];
        if ($box['first']) {
            // A box size from height or min-height/max-height applies when the text completes in one slice.
            $fixed = BoxGeometry::explicitHeight($style, $c);
            $floor = $fixed ?? BoxGeometry::minimumFill($style, $c);
            if ($floor === false || ($floor !== null && $c->height !== null && $floor > $c->height + 1e-9 && !$c->fresh)) {
                return LayoutResult::doesNotFit();
            }
            $box['size'] = ['fixed' => $fixed, 'floor' => $floor === null ? null : min($floor, $c->height ?? $floor), 'c' => $c];
        }
        ['lines' => $lines, 'boxes' => $boxes] = $this->lines(self::text($node), $style, $box['content'], $engine);
        $total = count($lines);
        $start = min(max(0, $token->index ?? 0), $total);
        if ($start >= $total) {
            return LayoutResult::complete($this->fragment($lines, $boxes, $start, $start, $style, $box, true));
        }

        $available = $c->height === null
            ? null
            : $c->height - ($box['first'] ? BoxGeometry::topEdge($style) : 0.0) - BoxGeometry::bottomEdge($style);
        // Text with a height or max-height that fits the space is laid out whole, then sized and placed; taller ones split.
        $fixed = $box['size']['fixed'] ?? null;
        $whole = $box['size'] !== null
            && (($fixed !== null && ($c->height === null || $fixed <= $c->height + 1e-9 || !$c->fresh)) || BoxGeometry::capsWhole($style, $c));
        $n = $whole ? $total - $start : self::fittingLines($boxes, $start, $total, $available);
        if ($n >= $total - $start) {
            $f = $this->fragment($lines, $boxes, $start, $total, $style, $box, true);
            if ($whole && $c->height !== null && $f->height > $c->height + 1e-9 && !$c->fresh) {
                return LayoutResult::doesNotFit();
            }
            return LayoutResult::complete($f);
        }

        $m = max(1, min($style->minLines, $total));
        if ($n < $m) {
            if (!$c->fresh) {
                return LayoutResult::doesNotFit();
            }
            // Orphans cannot be honoured on an empty page; with nothing fitting, one line overflows by design.
            $n = max(1, $n);
        } else {
            $remaining = $total - ($start + $n);
            if ($remaining < $m) {
                $n -= $m - $remaining;
                if ($n < $m) {
                    if (!$c->fresh) {
                        return LayoutResult::doesNotFit();
                    }
                    $n = max(1, $n);
                }
            }
        }

        $end = $start + $n;
        return LayoutResult::partial(
            $this->fragment($lines, $boxes, $start, $end, $style, $box, false),
            new BreakToken(index: $end),
        );
    }

    public function intrinsic(Node $node, ComputedStyle $style, LayoutEngine $engine): array
    {
        if ($style->width instanceof Length) {
            return ['min' => $style->width->points, 'max' => $style->width->points];
        }
        $edges = $style->padding->horizontal() + $style->borderWidth->horizontal();
        $items = $engine->context()->itemizer()->itemize(self::text($node)->spanList(), $style);
        $min = 0.0;
        $max = 0.0;
        $chunk = 0.0;
        $line = 0.0;
        $anywhere = $engine->breaksAnywhere();
        $glyph = 0.0;
        foreach ($items as $item) {
            if ($anywhere && $item->kind === 'word') {
                foreach (mb_str_split($item->text, 1, 'UTF-8') as $ch) {
                    $glyph = max($glyph, $item->font->advance($ch, $item->size, $item->style->letterSpacing));
                }
            }
            if ($item->kind === 'break') {
                $max = max($max, $line);
                $line = 0.0;
                $chunk = 0.0;
                continue;
            }
            $line += $item->width;
            if ($item->kind === 'space' || $item->kind === 'tab') {
                $chunk = 0.0;
                continue;
            }
            $chunk += $item->width;
            $min = max($min, $chunk);
            if ($item->breakAfter) {
                $chunk = 0.0;
            }
        }
        if ($anywhere) {
            $min = min($min, $glyph);
        }
        return ['min' => $min + $edges, 'max' => max($max, $line) + $edges];
    }

    private static function text(Node $node): Text
    {
        if (!$node instanceof Text) {
            throw new \LogicException(sprintf('TextLayouter cannot lay out %s.', $node::class));
        }
        return $node;
    }

    /**
     * Broken lines and their line boxes, cached per node, width and style.
     *
     * @return array{lines: list<Line>, boxes: list<array{baseline: float, height: float}>}
     */
    private function lines(Text $node, ComputedStyle $style, float $width, LayoutEngine $engine): array
    {
        $key = "w$width";
        $entries = $this->lineCache[$node] ?? [];
        $hit = $entries[$key] ?? null;
        if ($hit !== null && $hit['style'] == $style) {
            return ['lines' => $hit['lines'], 'boxes' => $hit['boxes']];
        }
        $items = $engine->context()->itemizer()->itemize($node->spanList(), $style);
        $lines = $items === [] ? [] : LineBreaker::break($items, $width);
        $boxes = array_map(fn (Line $line): array => $this->lineBox($line, $style, $engine), $lines);
        $entries[$key] = ['style' => $style, 'lines' => $lines, 'boxes' => $boxes];
        $this->lineCache[$node] = $entries;
        return ['lines' => $lines, 'boxes' => $boxes];
    }

    /** @return array{baseline: float, height: float} */
    private function lineBox(Line $line, ComputedStyle $paragraph, LayoutEngine $engine): array
    {
        /** @var list<array{float, float, float}> $metrics half-leading, ascent, descent */
        $metrics = [];
        foreach ($line->items as $item) {
            $metrics[] = self::metrics($item->font->ascent($item->size), $item->font->descent($item->size), $item->style->lineHeight * $item->size);
        }
        if ($metrics === []) {
            $font = $engine->context()->fonts->resolve(
                $paragraph->fontFamily,
                $paragraph->fontWeight,
                $paragraph->italic ? FontStyle::Italic : FontStyle::Normal,
            );
            $size = $paragraph->fontSize;
            $metrics[] = self::metrics($font->ascent($size), $font->descent($size), $paragraph->lineHeightPoints());
        }
        $baseline = max(array_map(static fn (array $m): float => $m[0] + $m[1], $metrics));
        $height = max(array_map(static fn (array $m): float => $baseline + $m[2] + $m[0], $metrics));
        return ['baseline' => $baseline, 'height' => $height];
    }

    /** @return array{float, float, float} */
    private static function metrics(float $ascent, float $descent, float $lineHeight): array
    {
        return [($lineHeight - ($ascent + $descent)) / 2, $ascent, $descent];
    }

    /**
     * The content height of a single-slice text box: its explicit height, else its lines grown
     * to min-height and capped by max-height.
     *
     * @param array{fixed: ?float, floor: ?float, c: Constraints} $size
     */
    private static function sized(ComputedStyle $style, array $size, float $lines): float
    {
        $edges = BoxGeometry::topEdge($style) + BoxGeometry::bottomEdge($style);
        if ($size['fixed'] !== null) {
            return max(0.0, min($size['fixed'], $size['c']->height ?? $size['fixed']) - $edges);
        }
        $height = $size['floor'] === null ? $lines : max($lines, $size['floor'] - $edges);
        $height = BoxGeometry::capContent($style, $size['c'], $height);
        // On a fresh page an over-tall box is cut to the space; elsewhere layout() moves it to the next page.
        return BoxGeometry::fitContent($style, $size['c'], $height) ?? $height;
    }

    /** @param array<int, array{baseline: float, height: float}> $boxes */
    private static function fittingLines(array $boxes, int $start, int $total, ?float $height): int
    {
        if ($height === null) {
            return $total - $start;
        }
        $sum = 0.0;
        $n = 0;
        for ($i = $start; $i < $total; $i++) {
            $sum += $boxes[$i]['height'];
            if ($sum > $height + 1e-9) {
                break;
            }
            $n++;
        }
        return $n;
    }

    /**
     * One slice of the text's box: lines $start..$end-1 in the content box,
     * top padding/border only on the first slice, bottom only on the last.
     *
     * @param list<Line> $lines
     * @param array<int, array{baseline: float, height: float}> $boxes
     * @param array{width: float, content: float, first: bool, size: ?array{fixed: ?float, floor: ?float, c: Constraints}} $box
     */
    private function fragment(array $lines, array $boxes, int $start, int $end, ComputedStyle $style, array $box, bool $last): Fragment
    {
        $height = 0.0;
        for ($i = $start; $i < $end; $i++) {
            $height += $boxes[$i]['height'];
        }
        if ($box['first'] && $last && $box['size'] !== null) {
            $height = self::sized($style, $box['size'], $height);
        }
        $f = new Fragment($box['width'], BoxGeometry::outerHeight($style, $height, $box['first'], $last));
        BoxGeometry::decorate($f, $style, $box['first'], $last);
        $ox = $style->borderWidth->left->points + $style->padding->left->points;
        $top = $box['first'] ? BoxGeometry::topEdge($style) : 0.0;
        for ($i = $start; $i < $end; $i++) {
            $this->paintLine($f, $lines[$i], $ox, $top, $boxes[$i], $style->textAlign, $box['content']);
            $top += $boxes[$i]['height'];
        }
        return $f;
    }

    /** @param array{baseline: float, height: float} $box */
    private function paintLine(Fragment $f, Line $line, float $ox, float $top, array $box, TextAlign $align, float $width): void
    {
        // Pre-wrap lines are never stretched: their spaces are layout (indentation, columns), not word gaps.
        $justified = $align === TextAlign::Justify && !$line->isLast && !$line->endsWithBreak
            && array_all($line->items, static fn (InlineItem $i): bool => $i->style->whiteSpace === WhiteSpace::Normal);
        $perSpace = 0.0;
        if ($justified) {
            $spaces = count(array_filter($line->items, static fn (InlineItem $i): bool => $i->kind === 'space'));
            if ($spaces > 0) {
                $perSpace = max(0.0, $width - $line->width) / $spaces;
            }
        }
        /** @var list<array{item: InlineItem, x: float, w: float}> $placed */
        $placed = [];
        $x = $ox;
        foreach ($line->items as $item) {
            $w = $item->width + ($item->kind === 'space' ? $perSpace : 0.0);
            $placed[] = ['item' => $item, 'x' => $x, 'w' => $w];
            $x += $w;
        }
        $baseline = $top + $box['baseline'];

        // Text runs: one per word on justified lines, otherwise merged by identical style.
        $lineWidth = $line->width;
        if (!$justified) {
            $merged = self::measureMerged($placed);
            $lineWidth += array_sum(array_column($merged, 'w')) - array_sum(array_column($placed, 'w'));
            $placed = $merged;
        }
        // Right and center use the width as drawn (merged runs kern across items), so text ends at the edge.
        $offset = match ($align) {
            TextAlign::Right => $width - $lineWidth,
            TextAlign::Center => ($width - $lineWidth) / 2,
            TextAlign::Left, TextAlign::Justify => 0.0,
        };
        if ($offset !== 0.0) {
            $placed = array_map(static fn (array $p): array => ['item' => $p['item'], 'x' => $p['x'] + $offset, 'w' => $p['w']], $placed);
        }
        $runs = $justified
            ? array_map(static fn (array $p): array => [$p], array_values(array_filter($placed, static fn (array $p): bool => $p['item']->kind === 'word')))
            : self::group($placed, self::runKey(...));
        // Highlights sit behind the text: one rect per contiguous same-style run, including stretched spaces.
        foreach (self::group($placed, self::styleKey(...)) as $group) {
            $bg = $group[0]['item']->style->background;
            if ($bg !== null && !$bg->isTransparent()) {
                $f->addOp(new Rect($group[0]['x'], $top, array_sum(array_column($group, 'w')), $box['height'], fill: $bg));
            }
        }
        foreach ($runs as $group) {
            $text = implode('', array_map(static fn (array $p): string => $p['item']->text, $group));
            if (trim($text) === '') {
                continue;
            }
            $first = $group[0]['item'];
            $f->addOp(new TextRun($group[0]['x'], $baseline, $text, $first->font, $first->size, $first->style->color, $first->style->letterSpacing));
        }

        // Decorations span contiguous same-style items, including stretched spaces.
        foreach (self::group($placed, self::styleKey(...)) as $group) {
            $s = $group[0]['item']->style;
            $size = $group[0]['item']->size;
            $x1 = $group[0]['x'];
            $x2 = $x1 + array_sum(array_column($group, 'w'));
            $thickness = max(0.5, 0.06 * $size);
            if ($s->underline) {
                $y = $baseline + 0.12 * $size;
                $f->addOp(new LineOp($x1, $y, $x2, $y, $s->color, $thickness));
            }
            if ($s->strike) {
                $y = $baseline - 0.3 * $size;
                $f->addOp(new LineOp($x1, $y, $x2, $y, $s->color, $thickness));
            }
        }

        foreach (self::group($placed, static fn (InlineItem $i): string => $i->href ?? '') as $group) {
            $href = $group[0]['item']->href;
            if ($href === null) {
                continue;
            }
            $f->addOp(new LinkOp($group[0]['x'], $top, array_sum(array_column($group, 'w')), $box['height'], $href));
        }
    }

    /**
     * A merged run is drawn as one string, so it is as wide as the font says
     * that string is (kerning across item boundaries included), not the sum of
     * its items. The difference goes to the run's last item and shifts every
     * later item on the line, so decorations and links match the drawn text. A tab
     * stop is fixed, so a tab absorbs the shift so far (its width shrinks, never
     * below zero) and the text after it lands on the stop.
     *
     * @param list<array{item: InlineItem, x: float, w: float}> $placed
     * @return list<array{item: InlineItem, x: float, w: float}>
     */
    private static function measureMerged(array $placed): array
    {
        $out = [];
        $shift = 0.0;
        foreach (self::group($placed, self::runKey(...)) as $group) {
            $delta = 0.0;
            if (count($group) > 1) {
                $first = $group[0]['item'];
                $text = implode('', array_map(static fn (array $p): string => $p['item']->text, $group));
                $delta = $first->font->advance($text, $first->size, $first->style->letterSpacing) - array_sum(array_column($group, 'w'));
            }
            $last = count($group) - 1;
            $isTab = $group[0]['item']->kind === 'tab';
            foreach ($group as $k => $p) {
                $p['x'] += $shift;
                if ($isTab) {
                    $p['w'] = max(0.0, $p['w'] - $shift);
                }
                if ($k === $last) {
                    $p['w'] += $delta;
                }
                $out[] = $p;
            }
            $shift = $isTab ? 0.0 : $shift + $delta;
        }
        return $out;
    }

    /**
     * Splits placed items into runs of consecutive items sharing a key.
     *
     * @param list<array{item: InlineItem, x: float, w: float}> $placed
     * @param \Closure(InlineItem): string $key
     * @return list<non-empty-list<array{item: InlineItem, x: float, w: float}>>
     */
    private static function group(array $placed, \Closure $key): array
    {
        $groups = [];
        $current = [];
        $currentKey = null;
        foreach ($placed as $p) {
            $k = $key($p['item']);
            if ($current !== [] && $k !== $currentKey) {
                $groups[] = $current;
                $current = [];
            }
            $current[] = $p;
            $currentKey = $k;
        }
        if ($current !== []) {
            $groups[] = $current;
        }
        return $groups;
    }

    /** Like styleKey, but a tab is a run of its own: text before and after it is drawn apart. */
    private static function runKey(InlineItem $i): string
    {
        return $i->kind === 'tab' ? 'tab' . spl_object_id($i) : self::styleKey($i);
    }

    private static function styleKey(InlineItem $i): string
    {
        $s = $i->style;
        return implode("\0", [
            $i->font->id(),
            (string) $i->size,
            (string) $s->color,
            (string) $s->letterSpacing,
            $s->underline ? 'u' : '',
            $s->strike ? 's' : '',
            (string) $s->background,
            $i->href ?? '',
        ]);
    }
}
