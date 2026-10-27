<?php

declare(strict_types=1);

namespace Papercut\Layout;

use Papercut\{Image, Node};
use Papercut\Paint\{Clip, DrawImage};
use Papercut\Style\{ComputedStyle, ImageFit};
use Papercut\Value\Length;

/**
 * Images are monolithic: taller than the space left means DoesNotFit, even
 * on a fresh page (the paginator owns the overflow policy).
 */
final class ImageLayouter implements Layouter
{
    private const float PT_PER_PX = 0.75;

    public function layout(Node $node, ComputedStyle $style, Constraints $c, ?BreakToken $token, LayoutEngine $engine): LayoutResult
    {
        if (!$node instanceof Image) {
            throw new \LogicException(sprintf('ImageLayouter cannot lay out %s.', $node::class));
        }
        $handle = $engine->context()->image($node);
        $iw = $handle->widthPx() * self::PT_PER_PX;
        $ih = $handle->heightPx() * self::PT_PER_PX;

        $edgesH = $style->padding->horizontal() + $style->borderWidth->horizontal();
        $edgesV = $style->padding->vertical() + $style->borderWidth->vertical();

        $size = ReplacedBox::size($style, $c, $iw, $ih);
        if ($size === null) {
            return LayoutResult::doesNotFit();
        }
        [$w, $h] = $size;

        $fragment = new Fragment($w, $h);
        BoxGeometry::decorate($fragment, $style, true, true);

        $bw = max(0.0, $w - $edgesH);
        $bh = max(0.0, $h - $edgesV);
        $ox = $style->padding->left->points + $style->borderWidth->left->points;
        $oy = $style->padding->top->points + $style->borderWidth->top->points;

        if ($iw > 0.0 && $ih > 0.0 && $bw > 0.0 && $bh > 0.0) {
            $fit = $node->fitMode();
            if ($fit === ImageFit::Fill) {
                $dw = $bw;
                $dh = $bh;
            } else {
                $widthLimited = $bw * $ih <= $bh * $iw;
                if ($widthLimited === ($fit === ImageFit::Contain)) {
                    $dw = $bw;
                    $dh = $bw * $ih / $iw;
                } else {
                    $dh = $bh;
                    $dw = $bh * $iw / $ih;
                }
            }
            $draw = new DrawImage($ox + ($bw - $dw) / 2, $oy + ($bh - $dh) / 2, $dw, $dh, $handle);
            $fragment->addOp($fit === ImageFit::Cover ? new Clip($ox, $oy, $bw, $bh, [$draw]) : $draw);
        }

        return LayoutResult::complete($fragment);
    }

    public function intrinsic(Node $node, ComputedStyle $style, LayoutEngine $engine): array
    {
        if (!$node instanceof Image) {
            throw new \LogicException(sprintf('ImageLayouter cannot measure %s.', $node::class));
        }
        $w = $style->width instanceof Length
            ? $style->width->points
            : $engine->context()->image($node)->widthPx() * self::PT_PER_PX + $style->padding->horizontal() + $style->borderWidth->horizontal();
        return ['min' => $w, 'max' => $w];
    }
}
