<?php

declare(strict_types=1);

namespace Papercut\Layout;

use Papercut\{Node, Svg};
use Papercut\Paint\{Clip, DrawSvg};
use Papercut\Style\ComputedStyle;
use Papercut\Value\Length;

/**
 * An SVG is monolithic, sized like an image from its natural size. Its view box is placed in the
 * content box by preserveAspectRatio, and anything drawn outside the box is clipped, as a
 * browser does.
 */
final class SvgLayouter implements Layouter
{
    /** @var array<string, true> warnings already given, by source */
    private array $warned = [];

    public function layout(Node $node, ComputedStyle $style, Constraints $c, ?BreakToken $token, LayoutEngine $engine): LayoutResult
    {
        if (!$node instanceof Svg) {
            throw new \LogicException(sprintf('SvgLayouter cannot lay out %s.', $node::class));
        }
        $svg = $node->document();
        $this->warnUnsupported($node, $engine->context());

        $size = ReplacedBox::size($style, $c, $svg->widthPt, $svg->heightPt);
        if ($size === null) {
            return LayoutResult::doesNotFit();
        }
        [$w, $h] = $size;

        $fragment = new Fragment($w, $h);
        BoxGeometry::decorate($fragment, $style, true, true);

        $bw = max(0.0, $w - $style->padding->horizontal() - $style->borderWidth->horizontal());
        $bh = max(0.0, $h - $style->padding->vertical() - $style->borderWidth->vertical());
        $ox = $style->padding->left->points + $style->borderWidth->left->points;
        $oy = $style->padding->top->points + $style->borderWidth->top->points;

        if ($bw > 0.0 && $bh > 0.0) {
            [$minX, $minY, $vw, $vh] = $svg->viewBox;
            $sx = $bw / $vw;
            $sy = $bh / $vh;
            $dx = $dy = 0.0;
            if ($svg->align !== 'none') {
                $sx = $sy = $svg->slice ? max($sx, $sy) : min($sx, $sy);
                $dx = self::offset(substr($svg->align, 1, 3), $bw - $vw * $sx);
                $dy = self::offset(substr($svg->align, 5, 3), $bh - $vh * $sy);
            }
            $draw = new DrawSvg($ox + $dx - $minX * $sx, $oy + $dy - $minY * $sy, $vw * $sx, $vh * $sy, $svg, $engine->context()->svgFonts($style));
            $fragment->addOp(new Clip($ox, $oy, $bw, $bh, [$draw]));
        }

        return LayoutResult::complete($fragment);
    }

    private static function offset(string $align, float $spare): float
    {
        return match ($align) {
            'Min' => 0.0,
            'Max' => $spare,
            default => $spare / 2,
        };
    }

    private function warnUnsupported(Svg $node, LayoutContext $ctx): void
    {
        $svg = $node->document();
        $key = sha1($svg->xml);
        if ($svg->unsupported === [] || isset($this->warned[$key])) {
            return;
        }
        $this->warned[$key] = true;
        $ctx->warn(sprintf('SVG uses %s, which PDF output does not draw yet; that part of the drawing is left out', implode(', ', $svg->unsupported)));
    }

    public function intrinsic(Node $node, ComputedStyle $style, LayoutEngine $engine): array
    {
        if (!$node instanceof Svg) {
            throw new \LogicException(sprintf('SvgLayouter cannot measure %s.', $node::class));
        }
        $w = $style->width instanceof Length
            ? $style->width->points
            : $node->document()->widthPt + $style->padding->horizontal() + $style->borderWidth->horizontal();

        return ['min' => $w, 'max' => $w];
    }
}
