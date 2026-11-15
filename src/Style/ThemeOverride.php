<?php

declare(strict_types=1);

namespace Papercut\Style;

use Papercut\Exception\InvalidValue;
use Papercut\Value\{Color, Edges, Length};

/**
 * A partial theme: the values it sets replace the theme's, the named styles
 * merge per name onto the theme's. Sizes are absolute lengths.
 */
final readonly class ThemeOverride
{
    /**
     * @param array<string, Style> $styles
     */
    public function __construct(
        public ?string $fontFamily = null,
        public ?Length $fontSize = null,
        public ?int $fontWeight = null,
        public ?Color $color = null,
        public ?float $lineHeight = null,
        public ?Edges $cellPadding = null,
        public ?int $minLines = null,
        public ?string $monoFamily = null,
        public ?string $codeTheme = null,
        public array $styles = [],
    ) {
        if ($fontSize !== null) {
            $fontSize->absolute('theme font size');
            if (!($fontSize->points > 0.0)) {
                throw InvalidValue::for('theme font size', (string) $fontSize)->because('it must be positive');
            }
        }
        if ($lineHeight !== null && !(is_finite($lineHeight) && $lineHeight > 0.0)) {
            throw InvalidValue::for('theme line height', InvalidValue::number($lineHeight))->because('it must be a finite number above 0');
        }
        if ($fontWeight !== null && ($fontWeight < 1 || $fontWeight > 1000)) {
            throw InvalidValue::for('theme font weight', (string) $fontWeight)->because('it must be a whole number from 1 to 1000');
        }
        if ($minLines !== null && $minLines < 0) {
            throw InvalidValue::for('theme min lines', (string) $minLines)->because('it must be 0 or more');
        }
        foreach ($styles as $name => $style) {
            if (!is_string($name) || $name === '') { // @phpstan-ignore function.alreadyNarrowedType
                throw InvalidValue::for('theme styles name', (string) $name)->because('styles must be keyed by a non-empty style name');
            }
            if (!$style instanceof Style) { // @phpstan-ignore instanceof.alwaysTrue
                throw InvalidValue::for('theme styles value', get_debug_type($style))->withContext('"' . $name . '"')->because('each entry in styles must be a Style');
            }
            Theme::assertStyleName($name);
        }
    }

    public function isEmpty(): bool
    {
        return $this->fontFamily === null && $this->fontSize === null && $this->fontWeight === null
            && $this->color === null && $this->lineHeight === null && $this->cellPadding === null
            && $this->minLines === null && $this->monoFamily === null && $this->codeTheme === null
            && $this->styles === [];
    }

    /** This override with $later on top: later values win, styles merge per name. */
    public function merge(self $later): self
    {
        $styles = $this->styles;
        foreach ($later->styles as $name => $style) {
            $styles[$name] = isset($styles[$name]) ? $styles[$name]->merge($style) : $style;
        }
        return new self(
            $later->fontFamily ?? $this->fontFamily,
            $later->fontSize ?? $this->fontSize,
            $later->fontWeight ?? $this->fontWeight,
            $later->color ?? $this->color,
            $later->lineHeight ?? $this->lineHeight,
            $later->cellPadding ?? $this->cellPadding,
            $later->minLines ?? $this->minLines,
            $later->monoFamily ?? $this->monoFamily,
            $later->codeTheme ?? $this->codeTheme,
            $styles,
        );
    }
}
