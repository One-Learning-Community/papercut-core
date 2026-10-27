<?php

declare(strict_types=1);

namespace Papercut\Layout;

use Papercut\Barcode\Bacon\BaconQrSvg;
use Papercut\Barcode\ModuleRects;
use Papercut\{Node, QrCode};
use Papercut\Paint\{DrawSvg, Rect};
use Papercut\Style\ComputedStyle;
use Papercut\Value\Length;
use Papercut\Vector\SvgDocument;

/**
 * A QR code is a monolithic square. Its side is the width (or height) when set, else 25mm,
 * clamped by min/max-width and the space available; an empty page that is too short shrinks it.
 * A plain code is drawn as rectangles; a styled one as the SVG Bacon draws, quiet zone included.
 */
final class QrCodeLayouter implements Layouter
{
    /** @var array<string, SvgDocument> styled codes already drawn, by their markup */
    private array $documents = [];

    public function layout(Node $node, ComputedStyle $style, Constraints $c, ?BreakToken $token, LayoutEngine $engine): LayoutResult
    {
        if (!$node instanceof QrCode) {
            throw new \LogicException(sprintf('QrCodeLayouter cannot lay out %s.', $node::class));
        }
        $side = self::side($style, $c);
        if ($c->height !== null && $side > $c->height + 1e-9) {
            $explicit = BoxGeometry::resolve($style->width, $c->width) !== null || BoxGeometry::resolve($style->height, $c->definiteHeight) !== null;
            if (!$c->fresh || $explicit) {
                return LayoutResult::doesNotFit();
            }
            $side = $c->height;
        }

        $fragment = new Fragment($side, $side);
        BoxGeometry::decorate($fragment, $style, true, true);

        $inner = min(
            $side - $style->padding->horizontal() - $style->borderWidth->horizontal(),
            $side - $style->padding->vertical() - $style->borderWidth->vertical(),
        );
        $design = $node->design();
        if ($inner > 0.0 && $design !== null && !($style->color->isTransparent() && $design->gradientFrom === null)) {
            $xml = BaconQrSvg::svg($node->modules(), $node->quietZoneModules(), $node->level(), $design, $style->color);
            $svg = $this->documents[sha1($xml)] ??= SvgDocument::parse($xml);
            $ox = $style->padding->left->points + $style->borderWidth->left->points;
            $oy = $style->padding->top->points + $style->borderWidth->top->points;
            $fragment->addOp(new DrawSvg($ox, $oy, $inner, $inner, $svg, $engine->context()->svgFonts($style)));
        } elseif ($inner > 0.0 && $design === null && !$style->color->isTransparent()) {
            $module = $inner / $node->span();
            $ox = $style->padding->left->points + $style->borderWidth->left->points + $node->quietZoneModules() * $module;
            $oy = $style->padding->top->points + $style->borderWidth->top->points + $node->quietZoneModules() * $module;
            foreach (ModuleRects::of($node->modules()) as $r) {
                $fragment->addOp(new Rect($ox + $r['x'] * $module, $oy + $r['y'] * $module, $r['w'] * $module, $r['h'] * $module, fill: $style->color));
            }
        }

        return LayoutResult::complete($fragment);
    }

    public function intrinsic(Node $node, ComputedStyle $style, LayoutEngine $engine): array
    {
        $w = $style->width instanceof Length ? $style->width->points : ($style->height instanceof Length ? $style->height->points : self::defaultSide());

        return ['min' => $w, 'max' => $w];
    }

    private static function side(ComputedStyle $style, Constraints $c): float
    {
        $side = BoxGeometry::resolve($style->width, $c->width)
            ?? BoxGeometry::resolve($style->height, $c->definiteHeight)
            ?? self::defaultSide();
        $min = BoxGeometry::resolve($style->minWidth, $c->width);
        $max = BoxGeometry::resolve($style->maxWidth, $c->width);
        if ($min !== null) {
            $side = max($side, $min);
        }
        if ($max !== null) {
            $side = min($side, $max);
        }

        return max(0.0, min($side, $c->width));
    }

    private static function defaultSide(): float
    {
        return \Papercut\mm(25)->points;
    }
}
