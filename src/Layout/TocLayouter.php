<?php

declare(strict_types=1);

namespace Papercut\Layout;

use Papercut\{Column, Node, Text, Toc};
use Papercut\Paint\{TextRun, TocSlot};
use Papercut\Style\ComputedStyle;
use Papercut\Text\{CachedFont, FontStyle};
use Papercut\Toc\TocLine;
use Papercut\View;

/**
 * A table of contents: the title, then one line per listed heading, stacked by
 * the block layouter. Each line keeps room for a page number and carries a
 * TocSlot the Painter fills in once the heading's page is known.
 *
 * @internal Part of Papercut's engine, not its public API. It may change in any release.
 */
final class TocLayouter implements Layouter
{
    /** Space between the title's text and the page number. */
    private const float GAP = 6.0;

    /** @var \SplObjectStorage<Toc, View> */
    private \SplObjectStorage $columns;

    public function __construct(private readonly BlockLayouter $block)
    {
        $this->columns = new \SplObjectStorage();
    }

    public function layout(Node $node, ComputedStyle $style, Constraints $c, ?BreakToken $token, LayoutEngine $engine): LayoutResult
    {
        if ($node instanceof TocLine) {
            return $this->line($node, $style, $c, $engine);
        }
        $toc = self::toc($node);
        return $this->block->layout($this->column($toc, $engine), $style, $c, $token, $engine);
    }

    public function intrinsic(Node $node, ComputedStyle $style, LayoutEngine $engine): array
    {
        if ($node instanceof TocLine) {
            $title = $engine->intrinsic($node->title, $style);
            $box = $this->numberWidth($node->title, $style, $engine) + self::GAP + $style->padding->horizontal() + $style->borderWidth->horizontal();
            return ['min' => $title['min'] + $box, 'max' => $title['max'] + $box];
        }
        return $this->block->intrinsic($this->column(self::toc($node), $engine), $style, $engine);
    }

    private static function toc(Node $node): Toc
    {
        if (!$node instanceof Toc) {
            throw new \LogicException(sprintf('TocLayouter cannot lay out %s.', $node::class));
        }
        return $node;
    }

    private function column(Toc $toc, LayoutEngine $engine): View
    {
        $entries = $engine->context()->toc->entries($toc->fromLevel(), $toc->toLevel());
        if ($entries === []) {
            $engine->context()->warnOnce('table of contents has no entries');
        }
        if (isset($this->columns[$toc])) {
            return $this->columns[$toc];
        }
        $children = [];
        if ($toc->titleText() !== null) {
            $children[] = Text::make($toc->titleText())->apply('toc.title')->keepWithNext();
        }
        foreach ($entries as $entry) {
            $children[] = new TocLine($entry, $toc->hasLeaders());
        }
        return $this->columns[$toc] = Column::make(...$children);
    }

    private function line(TocLine $line, ComputedStyle $style, Constraints $c, LayoutEngine $engine): LayoutResult
    {
        $entry = $engine->styleOf($line->title, $style);
        $font = self::font($entry, $engine);
        $number = $font->advance('999', $entry->fontSize, $entry->letterSpacing);
        // The line's own padding and border (the level indent) surround the title, which inherits its text styles.
        $left = $style->borderWidth->left->points + $style->padding->left->points;
        $top = BoxGeometry::topEdge($style);
        $edges = $top + BoxGeometry::bottomEdge($style);
        $titleWidth = max(0.0, $c->width - $number - self::GAP - $style->padding->horizontal() - $style->borderWidth->horizontal());
        $inner = $c->height === null ? null : max(0.0, $c->height - $edges);

        $res = $engine->layout($line->title, $style, new Constraints($titleWidth, $inner, $c->fresh), null, 0);
        if ($res->status !== Status::Complete || $res->fragment === null) {
            if (!$c->fresh) {
                return LayoutResult::doesNotFit();
            }
            // An entry never splits: on an empty page it overflows rather than break.
            $res = $engine->layout($line->title, $style, new Constraints($titleWidth, null, true), null, 0);
            if ($res->fragment === null) {
                return LayoutResult::doesNotFit();
            }
        }
        $title = $res->fragment;

        $f = new Fragment($c->width, $title->height + $edges);
        BoxGeometry::decorate($f, $style, true, true);
        $f->addChild($left, $top, $title);
        [$baseline, $leaderStart] = self::lastLine($title) ?? [$title->height - $font->descent($entry->fontSize), 0.0];
        $baseline += $top;
        $leaderStart += $left;
        $f->addSlot(new TocSlot(
            $line->entry->destination,
            $c->width - $number,
            $baseline,
            $number,
            $leaderStart,
            $line->leaders,
            $font,
            $entry->fontSize,
            $entry->color,
            $entry->letterSpacing,
            $c->width,
            $f->height,
        ));
        return LayoutResult::complete($f);
    }

    private function numberWidth(Text $title, ComputedStyle $parent, LayoutEngine $engine): float
    {
        $entry = $engine->styleOf($title, $parent);
        return self::font($entry, $engine)->advance('999', $entry->fontSize, $entry->letterSpacing);
    }

    private static function font(ComputedStyle $style, LayoutEngine $engine): CachedFont
    {
        return $engine->context()->fonts->resolve($style->fontFamily, $style->fontWeight, $style->italic ? FontStyle::Italic : FontStyle::Normal);
    }

    /**
     * The baseline of the lowest text line in $f, and the right edge of the text on it.
     *
     * @return ?array{float, float}
     */
    private static function lastLine(Fragment $f): ?array
    {
        $runs = self::runs($f, 0.0, 0.0);
        if ($runs === []) {
            return null;
        }
        $baseline = max(array_column($runs, 0));
        $end = 0.0;
        foreach ($runs as [$y, $right]) {
            if (abs($y - $baseline) < 1e-6) {
                $end = max($end, $right);
            }
        }
        return [$baseline, $end];
    }

    /**
     * Baseline and right edge of every text run, in the coordinates of the fragment lastLine() was given.
     *
     * @return list<array{float, float}>
     */
    private static function runs(Fragment $f, float $dx, float $dy): array
    {
        $out = [];
        foreach ($f->ops() as $op) {
            if ($op instanceof TextRun) {
                $out[] = [$dy + $op->baseline, $dx + $op->x + $op->font->advance($op->text, $op->size, $op->letterSpacing)];
            }
        }
        foreach ($f->children() as $child) {
            array_push($out, ...self::runs($child['fragment'], $dx + $child['x'], $dy + $child['y']));
        }
        return $out;
    }
}
