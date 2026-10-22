<?php

declare(strict_types=1);

namespace Papercut\Layout;

use Papercut\{Node, PageBreak, PageNumber, Spacer};
use Papercut\Paint\PageNumberSlot;
use Papercut\Style\ComputedStyle;
use Papercut\Text\{CachedFont, FontStyle};

/**
 * Spacer, PageBreak and PageNumber. Forced breaks and flex growth are the
 * parent's job; a Spacer here only takes its fixed (or stretched) height. A
 * PageNumber is one line box carrying a PageNumberSlot the Painter fills in.
 */
final class LeafLayouter implements Layouter
{
    public function layout(Node $node, ComputedStyle $style, Constraints $c, ?BreakToken $token, LayoutEngine $engine): LayoutResult
    {
        if ($node instanceof PageBreak) {
            return LayoutResult::complete(new Fragment($c->width, 0.0));
        }
        if ($node instanceof PageNumber) {
            return self::pageNumber($node, $style, $c, $engine);
        }
        if (!$node instanceof Spacer) {
            throw new \LogicException(sprintf('LeafLayouter cannot lay out %s.', $node::class));
        }
        $h = $c->definiteHeight ?? $node->fixedSize()->points ?? 0.0;
        if ($c->height !== null && $h > $c->height + 1e-9) {
            if (!$c->fresh) {
                return LayoutResult::doesNotFit();
            }
            $h = max(0.0, $c->height);
        }
        return LayoutResult::complete(new Fragment($c->width, $h));
    }

    public function intrinsic(Node $node, ComputedStyle $style, LayoutEngine $engine): array
    {
        if ($node instanceof PageNumber) {
            $text = strtr($node->format(), ['{page}' => '999', '{total}' => '999']);
            $w = self::font($style, $engine)->advance($text, $style->fontSize, $style->letterSpacing);
            return ['min' => $w, 'max' => $w];
        }
        $size = $node instanceof Spacer ? ($node->fixedSize()->points ?? 0.0) : 0.0;
        return ['min' => $size, 'max' => $size];
    }

    /** Like a text line with no items: on a fresh page it overflows rather than vanish. */
    private static function pageNumber(PageNumber $node, ComputedStyle $style, Constraints $c, LayoutEngine $engine): LayoutResult
    {
        $font = self::font($style, $engine);
        $size = $style->fontSize;
        $lineHeight = $style->lineHeightPoints();
        if ($c->height !== null && $lineHeight > $c->height + 1e-9 && !$c->fresh) {
            return LayoutResult::doesNotFit();
        }
        $ascent = $font->ascent($size);
        $baseline = ($lineHeight - ($ascent + $font->descent($size))) / 2 + $ascent;
        $f = new Fragment($c->width, $lineHeight);
        $f->addSlot(new PageNumberSlot(0.0, $baseline, $c->width, $node->format(), $font, $size, $style->color, $style->textAlign, $style->letterSpacing));
        return LayoutResult::complete($f);
    }

    private static function font(ComputedStyle $style, LayoutEngine $engine): CachedFont
    {
        return $engine->context()->fonts->resolve($style->fontFamily, $style->fontWeight, $style->italic ? FontStyle::Italic : FontStyle::Normal);
    }
}
