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

        [$w, $h] = self::size($style, $c, $iw, $ih);

        if ($c->height !== null && $h > $c->height + 1e-9) {
            // Like the width limit, an empty page's height shrinks the image, unless its height was set explicitly.
            $explicit = BoxGeometry::resolve($style->height, $c->definiteHeight) !== null;
            if (!$c->fresh || $explicit || $c->height <= $edgesV) {
                return LayoutResult::doesNotFit();
            }
            $w = $edgesH + max(0.0, $w - $edgesH) * ($c->height - $edgesV) / ($h - $edgesV);
            $h = $c->height;
        }

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

    /**
     * Border-box size: explicit width/height (the other one derived from the
     * image's aspect ratio), else the intrinsic size; then min/max clamps,
     * then scaled down to fit c.width. Aspect is kept unless both were set,
     * or a min clamp on one axis would break a max on the other (max wins).
     *
     * @return array{float, float}
     */
    private static function size(ComputedStyle $style, Constraints $c, float $iw, float $ih): array
    {
        $edgesH = $style->padding->horizontal() + $style->borderWidth->horizontal();
        $edgesV = $style->padding->vertical() + $style->borderWidth->vertical();
        $toH = static fn (float $w): float => $iw > 0.0 ? max(0.0, $w - $edgesH) * $ih / $iw + $edgesV : $edgesV;
        $toW = static fn (float $h): float => $ih > 0.0 ? max(0.0, $h - $edgesV) * $iw / $ih + $edgesH : $edgesH;

        $w = BoxGeometry::resolve($style->width, $c->width);
        $h = BoxGeometry::resolve($style->height, $c->definiteHeight);
        $keep = $w === null || $h === null;
        if ($w === null && $h === null) {
            $w = $iw + $edgesH;
            $h = $toH($w);
        } elseif ($h === null) {
            $h = $toH((float) $w);
        } elseif ($w === null) {
            $w = $toW($h);
        }

        $clamped = self::clamp($w, BoxGeometry::resolve($style->minWidth, $c->width), BoxGeometry::resolve($style->maxWidth, $c->width));
        if ($clamped !== $w) {
            $h = $keep ? $toH($clamped) : $h;
            $w = $clamped;
        }
        $clamped = self::clamp($h, BoxGeometry::resolve($style->minHeight, $c->definiteHeight), BoxGeometry::resolve($style->maxHeight, $c->definiteHeight));
        if ($clamped !== $h) {
            $w = $keep ? $toW($clamped) : $w;
            $h = $clamped;
        }

        // A min clamp on one axis can push the other past its max: the max wins, at the cost of the aspect ratio.
        $maxW = BoxGeometry::resolve($style->maxWidth, $c->width);
        $maxH = BoxGeometry::resolve($style->maxHeight, $c->definiteHeight);
        $w = $maxW === null ? $w : min($w, $maxW);
        $h = $maxH === null ? $h : min($h, $maxH);

        $limit = max(0.0, $c->width);
        if ($w > $limit + 1e-9) {
            $h = $keep ? $toH($limit) : ($w > 0.0 ? $h * $limit / $w : $h);
            $w = $limit;
        }
        return [max(0.0, $w), max(0.0, $h)];
    }

    private static function clamp(float $v, ?float $min, ?float $max): float
    {
        if ($max !== null) {
            $v = min($v, $max);
        }
        if ($min !== null) {
            $v = max($v, $min);
        }
        return $v;
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
