<?php

declare(strict_types=1);

namespace Papercut\Layout;

use Papercut\{Equation, Node};
use Papercut\Math\Layout\Box;
use Papercut\Style\{ComputedStyle, TextAlign};

/**
 * A displayed equation is a monolithic box as tall as its formula, laid out in display style at the text's size
 * and drawn in its colour. It never splits, and a formula wider than the space overflows it, as an overlong word does.
 * The math font's script-size alternates are off, because the PDF can only draw glyphs that have a code point.
 *
 * @internal Part of Papercut's engine, not its public API. It may change in any release.
 */
final class EquationLayouter implements Layouter
{
    public function layout(Node $node, ComputedStyle $style, Constraints $c, ?BreakToken $token, LayoutEngine $engine): LayoutResult
    {
        if (!$node instanceof Equation) {
            throw new \LogicException(sprintf('EquationLayouter cannot lay out %s.', $node::class));
        }
        $box = $this->box($node, $style, $engine);
        $width = BoxGeometry::resolve($style->width, $c->width) ?? $c->width;
        $min = BoxGeometry::resolve($style->minWidth, $c->width);
        $max = BoxGeometry::resolve($style->maxWidth, $c->width);
        if ($min !== null) {
            $width = max($width, $min);
        }
        if ($max !== null) {
            $width = min($width, $max);
        }
        $height = $box->ascent + $box->descent + BoxGeometry::topEdge($style) + BoxGeometry::bottomEdge($style);
        if ($c->height !== null && $height > $c->height + 1e-9) {
            return LayoutResult::doesNotFit();
        }

        $fragment = new Fragment($width, $height);
        BoxGeometry::decorate($fragment, $style, true, true);

        $left = $style->padding->left->points + $style->borderWidth->left->points;
        $free = $width - $left - $style->padding->right->points - $style->borderWidth->right->points - $box->width;
        $x = $left + match ($style->textAlign) {
            TextAlign::Center => max(0.0, $free / 2),
            TextAlign::Right => max(0.0, $free),
            default => 0.0,
        };
        $math = $engine->context()->fonts->mathFont();
        MathPainter::paint($fragment, $box, $x, BoxGeometry::topEdge($style) + $box->ascent, $style->color, $math['handle'], $math['font'], $engine, $style);

        return LayoutResult::complete($fragment);
    }

    public function intrinsic(Node $node, ComputedStyle $style, LayoutEngine $engine): array
    {
        if (!$node instanceof Equation) {
            throw new \LogicException(sprintf('EquationLayouter cannot measure %s.', $node::class));
        }
        $w = $this->box($node, $style, $engine)->width + $style->padding->horizontal() + $style->borderWidth->horizontal();

        return ['min' => $w, 'max' => $w];
    }

    private function box(Equation $node, ComputedStyle $style, LayoutEngine $engine): Box
    {
        return $engine->context()->itemizer()->mathBox($node->math(), $style, true);
    }
}
