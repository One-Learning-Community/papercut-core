<?php

declare(strict_types=1);

namespace Papercut\Layout;

use Papercut\Node;
use Papercut\Paint\TextRun;
use Papercut\Style\ComputedStyle;

/**
 * Lays out a list item's content beside its gutter, with the marker
 * right-aligned in the gutter on the item's first slice only.
 */
final class MarkedItemLayouter implements Layouter
{
    public function layout(Node $node, ComputedStyle $style, Constraints $c, ?BreakToken $token, LayoutEngine $engine): LayoutResult
    {
        $item = self::item($node);
        $content = $c->withWidth(max(0.0, $c->width - $item->gutter));
        $res = $engine->blockLayouter()->layout($item->content, $style, $content, $token, $engine);
        if ($res->status === Status::DoesNotFit || $res->fragment === null) {
            return $res;
        }

        $body = $res->fragment;
        $f = new Fragment($c->width, $body->height);
        if ($token === null && $item->marker !== null && $item->content->children() !== []) {
            $marker = self::marker($item, $style, $engine);
            if ($marker !== null) {
                $y = BoxGeometry::topEdge($style);
                $contentBaseline = self::firstBaseline($body);
                $markerBaseline = self::firstBaseline($marker);
                if ($contentBaseline !== null && $markerBaseline !== null) {
                    // a marker taller than the first line cannot rise above the item
                    $y = max(0.0, $contentBaseline - $markerBaseline);
                }
                $f->addChild($item->gutter - $item->gap - $marker->width, $y, $marker);
                $f->height = max($f->height, min($y + $marker->height, $c->height ?? INF));
            }
        }
        $f->addChild($item->gutter, 0.0, $body);

        return $res->status === Status::Partial && $res->token !== null
            ? LayoutResult::partial($f, $res->token)
            : LayoutResult::complete($f);
    }

    public function intrinsic(Node $node, ComputedStyle $style, LayoutEngine $engine): array
    {
        $item = self::item($node);
        $size = $engine->blockLayouter()->intrinsic($item->content, $style, $engine);
        return ['min' => $size['min'] + $item->gutter, 'max' => $size['max'] + $item->gutter];
    }

    /** The marker laid out at its own intrinsic width, so its fragment's right edge is the label's. */
    private static function marker(MarkedItem $item, ComputedStyle $style, LayoutEngine $engine): ?Fragment
    {
        if ($item->marker === null) {
            return null;
        }
        $width = $engine->intrinsic($item->marker, $style)['max'];
        $res = $engine->layout($item->marker, $style, new Constraints($width, null, true), pathName: 'marker');
        return $res->fragment;
    }

    /** Baseline of the first TextRun in paint order, in $f's coordinates; null without text. */
    private static function firstBaseline(Fragment $f): ?float
    {
        foreach ($f->ops() as $op) {
            if ($op instanceof TextRun) {
                return $op->baseline;
            }
        }
        foreach ($f->children() as $child) {
            $baseline = self::firstBaseline($child['fragment']);
            if ($baseline !== null) {
                return $child['y'] + $baseline;
            }
        }
        return null;
    }

    private static function item(Node $node): MarkedItem
    {
        if (!$node instanceof MarkedItem) {
            throw new \LogicException(sprintf('MarkedItemLayouter cannot lay out %s.', $node::class));
        }
        return $node;
    }
}
