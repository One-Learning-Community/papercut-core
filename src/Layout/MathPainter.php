<?php

declare(strict_types=1);

namespace Papercut\Layout;

use Papercut\Math\Font\TrueTypeFont;
use Papercut\Math\Layout\{Box, Glyph, Rule};
use Papercut\Math\Render\SvgRenderer;
use Papercut\Paint\{DrawSvg, Rect, TextRun};
use Papercut\Style\ComputedStyle;
use Papercut\Text\CachedFont;
use Papercut\Value\Color;
use Papercut\Vector\SvgDocument;

/**
 * Paints a laid-out formula into a fragment. A glyph that stands for one character the math font draws as that
 * character is text, so it can be selected and searched. A glyph the math font lacks is set in the font the text
 * would use (a fallback family when one has it). Every other glyph (stretched fences, radical signs, assembly
 * pieces, variants with no code point of their own) is drawn as a path, all in one SVG; rules are rectangles.
 *
 * @internal Part of Papercut's engine, not its public API. It may change in any release.
 */
final class MathPainter
{
    private function __construct()
    {
    }

    /**
     * $x and $baseline place the formula's origin in the fragment: its left edge and its baseline.
     */
    public static function paint(
        Fragment $f,
        Box $box,
        float $x,
        float $baseline,
        Color $color,
        CachedFont $handle,
        TrueTypeFont $font,
        LayoutEngine $engine,
        ComputedStyle $style,
    ): void {
        if ($color->isTransparent()) {
            return;
        }
        /** @var list<Glyph> $paths */
        $paths = [];
        foreach ($box->items as $item) {
            if ($item instanceof Rule) {
                $f->addOp(new Rect($x + $item->x, $baseline + $item->y, $item->w, $item->h, fill: $color));
                continue;
            }
            if (!$item instanceof Glyph) {
                continue;
            }
            $cp = self::codePoint($item->text);
            if ($cp === null) {
                $paths[] = $item;
                continue;
            }
            $gid = $font->gid($cp);
            if ($gid === $item->gid && $gid !== 0) {
                $f->addOp(new TextRun($x + $item->x, $baseline + $item->y, $item->text ?? '', $handle, $item->size, $color));
                continue;
            }
            if ($gid === 0) {
                // The math font does not have it: set it the way a paragraph would.
                foreach ($engine->context()->itemizer()->runsFor($item->text ?? '', $style) as [$text, $face]) {
                    $f->addOp(new TextRun($x + $item->x, $baseline + $item->y, $text, $face, $item->size, $color));
                }
                continue;
            }
            $paths[] = $item; // a variant of the character, not the character's own glyph
        }
        if ($paths !== []) {
            // php-pdf's SVG drawing ignores the view box's origin, so the SVG is drawn from a zero origin: the glyphs are
            // moved down by the ascent into a box with none, which makes the view box "0 0 width height".
            $height = $box->ascent + $box->descent;
            $renderer = new SvgRenderer($font, sprintf('#%02x%02x%02x', $color->r, $color->g, $color->b), $color->alpha);
            $svg = SvgDocument::parse($renderer->glyphPaths(
                array_map(static fn (Glyph $g): Glyph => $g->translated(0.0, $box->ascent), $paths),
                new Box($box->width, 0.0, $height),
            ));
            $f->addOp(new DrawSvg($x, $baseline - $box->ascent, $box->width, $height, $svg, $engine->context()->svgFonts($style)));
        }
    }

    /** The code point of a string of exactly one, else null. */
    private static function codePoint(?string $text): ?int
    {
        if ($text === null || $text === '') {
            return null;
        }
        $cp = mb_ord($text, 'UTF-8');
        return mb_strlen($text, 'UTF-8') === 1 ? $cp : null;
    }
}
