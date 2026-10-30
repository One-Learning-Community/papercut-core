<?php

declare(strict_types=1);

namespace Papercut\Layout;

use Papercut\{Node, PdfPage};
use Papercut\Paint\DrawPdfPage;
use Papercut\Style\ComputedStyle;
use Papercut\Value\Length;

/**
 * A page of another PDF is monolithic, sized like an image from the page's own size. When both
 * width and height are set, the page keeps its proportions and is centred in its box.
 */
final class PdfPageLayouter implements Layouter
{
    public function layout(Node $node, ComputedStyle $style, Constraints $c, ?BreakToken $token, LayoutEngine $engine): LayoutResult
    {
        if (!$node instanceof PdfPage) {
            throw new \LogicException(sprintf('PdfPageLayouter cannot lay out %s.', $node::class));
        }
        $page = $engine->context()->pdfPage($node);
        $pw = $page->widthPt();
        $ph = $page->heightPt();

        $size = ReplacedBox::size($style, $c, $pw, $ph);
        if ($size === null) {
            return LayoutResult::doesNotFit();
        }
        [$w, $h] = $size;

        $fragment = new Fragment($w, $h);
        BoxGeometry::decorate($fragment, $style, true, true);

        $bw = max(0.0, $w - $style->padding->horizontal() - $style->borderWidth->horizontal());
        $bh = max(0.0, $h - $style->padding->vertical() - $style->borderWidth->vertical());
        if ($pw > 0.0 && $ph > 0.0 && $bw > 0.0 && $bh > 0.0) {
            $scale = min($bw / $pw, $bh / $ph);
            $dw = $pw * $scale;
            $dh = $ph * $scale;
            $ox = $style->padding->left->points + $style->borderWidth->left->points;
            $oy = $style->padding->top->points + $style->borderWidth->top->points;
            $fragment->addOp(new DrawPdfPage($ox + ($bw - $dw) / 2, $oy + ($bh - $dh) / 2, $dw, $dh, $page));
        }

        return LayoutResult::complete($fragment);
    }

    public function intrinsic(Node $node, ComputedStyle $style, LayoutEngine $engine): array
    {
        if (!$node instanceof PdfPage) {
            throw new \LogicException(sprintf('PdfPageLayouter cannot measure %s.', $node::class));
        }
        $natural = $style->width instanceof Length ? 0.0 : $engine->context()->pdfPage($node)->widthPt();
        return ReplacedBox::intrinsic($style, $natural, $engine);
    }
}
