<?php

declare(strict_types=1);

namespace Papercut\Style;

use Papercut\Exception\InvalidValue;
use Papercut\Value\{Auto, Color, Edges, Length, Percent};

/**
 * Fully resolved style for one node. Text properties and minLines inherit
 * from the parent; everything else resets per node.
 */
final readonly class ComputedStyle
{
    public function __construct(
        public Length|Percent|Auto $width,
        public Length|Percent|Auto $height,
        public Length|Percent|null $minWidth,
        public Length|Percent|null $maxWidth,
        public Length|Percent|null $minHeight,
        public Length|Percent|null $maxHeight,
        public Edges $padding,
        public Edges $margin,
        public Edges $borderWidth,
        public Color $borderColor,
        public BorderStyle $borderStyle,
        public float $radius,
        public ?Color $background,
        public float $opacity,
        public Direction $direction,
        public float $gap,
        public Justify $justify,
        public Align $alignItems,
        public ?Align $alignSelf,
        public float $grow,
        public float $shrink,
        public Length|Percent|Auto $basis,
        public bool $wrap,
        public string $fontFamily,
        public float $fontSize,
        public int $fontWeight,
        public bool $italic,
        public Color $color,
        public float $lineHeight,
        public float $letterSpacing,
        public TextAlign $textAlign,
        public bool $underline,
        public bool $strike,
        public bool $keepTogether,
        public bool $keepWithNext,
        public bool $breakBefore,
        public bool $breakAfter,
        public int $minLines,
    ) {
    }

    public static function root(Theme $theme): self
    {
        return new self(
            width: Auto::instance(),
            height: Auto::instance(),
            minWidth: null,
            maxWidth: null,
            minHeight: null,
            maxHeight: null,
            padding: Edges::zero(),
            margin: Edges::zero(),
            borderWidth: Edges::zero(),
            borderColor: Color::black(),
            borderStyle: BorderStyle::Solid,
            radius: 0.0,
            background: null,
            opacity: 1.0,
            direction: Direction::Column,
            gap: 0.0,
            justify: Justify::Start,
            alignItems: Align::Stretch,
            alignSelf: null,
            grow: 0.0,
            shrink: 1.0,
            basis: Auto::instance(),
            wrap: false,
            fontFamily: $theme->fontFamily,
            fontSize: $theme->fontSize,
            fontWeight: $theme->fontWeight,
            italic: false,
            color: $theme->color,
            lineHeight: $theme->lineHeight,
            letterSpacing: 0.0,
            textAlign: TextAlign::Left,
            underline: false,
            strike: false,
            keepTogether: false,
            keepWithNext: false,
            breakBefore: false,
            breakAfter: false,
            minLines: $theme->minLines,
        );
    }

    public function child(Style $style): self
    {
        // A relative font size is a share of the parent's; every other em/lh then uses this element's own size.
        $fontSize = $style->fontSize?->resolve($this->fontSize, $this->lineHeightPoints());
        if ($fontSize !== null && !($fontSize->points > 0.0)) {
            throw InvalidValue::for('font size', (string) $style->fontSize);
        }
        if ($style->lineHeight !== null && $style->lineHeight <= 0.0) {
            throw InvalidValue::for('line height', InvalidValue::number($style->lineHeight));
        }
        $em = $fontSize->points ?? $this->fontSize;
        $lh = ($style->lineHeight ?? $this->lineHeight) * $em;
        $len = static fn (mixed $v): mixed => $v instanceof Length ? $v->resolve($em, $lh) : $v;
        $edges = static fn (?Edges $e): ?Edges => $e?->resolve($em, $lh);

        return new self(
            width: $len($style->width) ?? Auto::instance(),
            height: $len($style->height) ?? Auto::instance(),
            minWidth: $len($style->minWidth),
            maxWidth: $len($style->maxWidth),
            minHeight: $len($style->minHeight),
            maxHeight: $len($style->maxHeight),
            padding: $edges($style->padding) ?? Edges::zero(),
            margin: $edges($style->margin) ?? Edges::zero(),
            borderWidth: $edges($style->borderWidth) ?? Edges::zero(),
            borderColor: $style->borderColor ?? Color::black(),
            borderStyle: $style->borderStyle ?? BorderStyle::Solid,
            radius: $style->radius?->resolve($em, $lh)->points ?? 0.0,
            background: $style->background,
            opacity: $style->opacity ?? 1.0,
            direction: $style->direction ?? Direction::Column,
            gap: $style->gap?->resolve($em, $lh)->points ?? 0.0,
            justify: $style->justify ?? Justify::Start,
            alignItems: $style->alignItems ?? Align::Stretch,
            alignSelf: $style->alignSelf,
            grow: $style->grow ?? 0.0,
            shrink: $style->shrink ?? 1.0,
            basis: $len($style->basis) ?? Auto::instance(),
            wrap: $style->wrap ?? false,
            fontFamily: $style->fontFamily ?? $this->fontFamily,
            fontSize: $em,
            fontWeight: $style->fontWeight ?? $this->fontWeight,
            italic: $style->italic ?? $this->italic,
            color: $style->color ?? $this->color,
            lineHeight: $style->lineHeight ?? $this->lineHeight,
            letterSpacing: $style->letterSpacing?->resolve($em, $lh)->points ?? $this->letterSpacing,
            textAlign: $style->textAlign ?? $this->textAlign,
            underline: $style->underline ?? $this->underline,
            strike: $style->strike ?? $this->strike,
            keepTogether: $style->keepTogether ?? false,
            keepWithNext: $style->keepWithNext ?? false,
            breakBefore: $style->breakBefore ?? false,
            breakAfter: $style->breakAfter ?? false,
            minLines: $style->minLines ?? $this->minLines,
        );
    }

    public function lineHeightPoints(): float
    {
        return $this->lineHeight * $this->fontSize;
    }
}
