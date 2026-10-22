<?php

declare(strict_types=1);

namespace Papercut\Style;

use Papercut\Decorator\DefaultStyles;
use Papercut\Exception\UnknownAttribute;
use Papercut\Support\Suggest;
use Papercut\Value\{Color, Edges, Length};

use function Papercut\pt;

final class Theme
{
    private const array HEADING_SIZES = [1 => 24.0, 2 => 18.0, 3 => 14.0, 4 => 12.0];

    /**
     * @param array<string, Style> $styles
     */
    public function __construct(
        public readonly string $fontFamily,
        public readonly float $fontSize,
        public readonly int $fontWeight,
        public readonly Color $color,
        public readonly float $lineHeight,
        public readonly Edges $cellPadding,
        public readonly int $minLines,
        private readonly array $styles = [],
    ) {
    }

    public static function default(): self
    {
        return new self(
            fontFamily: 'Inter',
            fontSize: 10.0,
            fontWeight: 400,
            color: Color::parse('slate-900'),
            lineHeight: 1.4,
            cellPadding: Edges::of(pt(4), pt(6)),
            minLines: 2,
        );
    }

    public function heading(int $level): Style
    {
        $size = self::HEADING_SIZES[$level]
            ?? throw new \InvalidArgumentException(sprintf('Heading level must be 1-4, got %d.', $level));

        return Style::make(
            fontSize: pt($size),
            fontWeight: 700,
            margin: Edges::of(Length::zero(), Length::zero(), pt($size * 0.5), Length::zero()),
        );
    }

    public function paragraph(): Style
    {
        return Style::make(margin: Edges::of(Length::zero(), Length::zero(), pt(6), Length::zero()));
    }

    public function withStyle(string $name, Style $style): self
    {
        if (preg_match('/\s/', $name) === 1) {
            throw new \InvalidArgumentException("Style name '$name' must not contain whitespace; it could never be resolved.");
        }
        $styles = $this->styles;
        $styles[$name] = $style;
        return $this->withStyles($styles);
    }

    /**
     * One style or several, space-separated and merged left to right. Theme
     * styles shadow the built-in decorator defaults of the same name.
     */
    public function named(string $names): Style
    {
        $parts = preg_split('/\s+/', trim($names), -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false || $parts === []) {
            throw UnknownAttribute::for($names, null, 'style');
        }
        $merged = null;
        foreach ($parts as $name) {
            $style = $this->styles[$name] ?? DefaultStyles::get($name)
                ?? throw UnknownAttribute::for($name, Suggest::closest($name, [
                    ...array_map(strval(...), array_keys($this->styles)),
                    ...DefaultStyles::names(),
                ]), 'style');
            $merged = $merged === null ? $style : $merged->merge($style);
        }
        return $merged;
    }

    public function with(
        ?string $fontFamily = null,
        ?float $fontSize = null,
        ?int $fontWeight = null,
        ?Color $color = null,
        ?float $lineHeight = null,
        ?Edges $cellPadding = null,
        ?int $minLines = null,
    ): self {
        return new self(
            $fontFamily ?? $this->fontFamily,
            $fontSize ?? $this->fontSize,
            $fontWeight ?? $this->fontWeight,
            $color ?? $this->color,
            $lineHeight ?? $this->lineHeight,
            $cellPadding ?? $this->cellPadding,
            $minLines ?? $this->minLines,
            $this->styles,
        );
    }

    /**
     * @param array<string, Style> $styles
     */
    private function withStyles(array $styles): self
    {
        return new self(
            $this->fontFamily,
            $this->fontSize,
            $this->fontWeight,
            $this->color,
            $this->lineHeight,
            $this->cellPadding,
            $this->minLines,
            $styles,
        );
    }
}
