<?php

declare(strict_types=1);

namespace Papercut\Concerns;

use Papercut\Style\{Align, BorderStyle, Style, TextAlign};
use Papercut\Support\EnumValue;
use Papercut\Value\{Auto, Color, Edges, Length, Percent};

/**
 * Immutable style modifiers: each returns a modified clone.
 */
trait HasStyle
{
    private ?Style $style = null;
    private ?string $styleName = null;

    public function style(): Style
    {
        return $this->style ?? new Style();
    }

    public function styleName(): ?string
    {
        return $this->styleName;
    }

    protected function withStyle(Style $patch): static
    {
        $clone = clone $this;
        $clone->style = $this->style()->merge($patch);
        return $clone;
    }

    public function width(Length|Percent|Auto $v): static
    {
        return $this->withStyle(new Style(width: $v));
    }

    public function height(Length|Percent|Auto $v): static
    {
        return $this->withStyle(new Style(height: $v));
    }

    public function minWidth(Length|Percent $v): static
    {
        return $this->withStyle(new Style(minWidth: $v));
    }

    public function maxWidth(Length|Percent $v): static
    {
        return $this->withStyle(new Style(maxWidth: $v));
    }

    public function minHeight(Length|Percent $v): static
    {
        return $this->withStyle(new Style(minHeight: $v));
    }

    public function maxHeight(Length|Percent $v): static
    {
        return $this->withStyle(new Style(maxHeight: $v));
    }

    public function padding(Length|Edges ...$v): static
    {
        return $this->withStyle(new Style(padding: self::edgesFrom($v)));
    }

    public function margin(Length|Edges ...$v): static
    {
        return $this->withStyle(new Style(margin: self::edgesFrom($v)));
    }

    /**
     * @param array<Length|Edges> $v
     */
    private static function edgesFrom(array $v): Edges
    {
        $v = array_values($v);
        if (count($v) === 1 && $v[0] instanceof Edges) {
            return $v[0];
        }
        $lengths = [];
        foreach ($v as $item) {
            if (!$item instanceof Length) {
                throw new \InvalidArgumentException('Pass one Edges or 1-4 Lengths.');
            }
            $lengths[] = $item;
        }
        if ($lengths === [] || count($lengths) > 4) {
            throw new \InvalidArgumentException('Pass one Edges or 1-4 Lengths.');
        }
        return Edges::of(...$lengths);
    }

    /** @throws \Papercut\Exception\InvalidValue for a $style string that is not a border style */
    public function border(Length $width, Color|string|null $color = null, BorderStyle|string|null $style = null): static
    {
        $style = $style === null ? null : EnumValue::from(BorderStyle::class, $style);
        return $this->withStyle(new Style(borderWidth: Edges::all($width), borderColor: $color === null ? null : self::toColor($color), borderStyle: $style));
    }

    public function borderWidth(Edges|Length $v): static
    {
        return $this->withStyle(new Style(borderWidth: $v instanceof Edges ? $v : Edges::all($v)));
    }

    public function borderColor(Color|string $c): static
    {
        return $this->withStyle(new Style(borderColor: self::toColor($c)));
    }

    public function radius(Length $v): static
    {
        return $this->withStyle(new Style(radius: $v));
    }

    public function background(Color|string $c): static
    {
        return $this->withStyle(new Style(background: self::toColor($c)));
    }

    public function opacity(float $v): static
    {
        return $this->withStyle(new Style(opacity: $v));
    }

    public function grow(float $v = 1): static
    {
        return $this->withStyle(new Style(grow: $v));
    }

    public function shrink(float $v): static
    {
        return $this->withStyle(new Style(shrink: $v));
    }

    public function basis(Length|Percent|Auto $v): static
    {
        return $this->withStyle(new Style(basis: $v));
    }

    /** @throws \Papercut\Exception\InvalidValue for a string that is not an alignment */
    public function alignSelf(Align|string $v): static
    {
        $v = EnumValue::from(Align::class, $v);
        return $this->withStyle(new Style(alignSelf: $v));
    }

    public function font(string $family): static
    {
        return $this->withStyle(new Style(fontFamily: $family));
    }

    public function size(Length $v): static
    {
        return $this->withStyle(new Style(fontSize: $v));
    }

    public function weight(int $v): static
    {
        return $this->withStyle(new Style(fontWeight: $v));
    }

    public function bold(bool $on = true): static
    {
        return $this->withStyle(new Style(fontWeight: $on ? 700 : 400));
    }

    public function italic(bool $on = true): static
    {
        return $this->withStyle(new Style(italic: $on));
    }

    public function color(Color|string $c): static
    {
        return $this->withStyle(new Style(color: self::toColor($c)));
    }

    public function lineHeight(float $v): static
    {
        return $this->withStyle(new Style(lineHeight: $v));
    }

    public function letterSpacing(Length $v): static
    {
        return $this->withStyle(new Style(letterSpacing: $v));
    }

    /** @throws \Papercut\Exception\InvalidValue for a string that is not a text alignment */
    public function textAlign(TextAlign|string $v): static
    {
        $v = EnumValue::from(TextAlign::class, $v);
        return $this->withStyle(new Style(textAlign: $v));
    }

    public function left(): static
    {
        return $this->textAlign(TextAlign::Left);
    }

    public function center(): static
    {
        return $this->textAlign(TextAlign::Center);
    }

    public function right(): static
    {
        return $this->textAlign(TextAlign::Right);
    }

    public function justify(): static
    {
        return $this->textAlign(TextAlign::Justify);
    }

    public function underline(bool $on = true): static
    {
        return $this->withStyle(new Style(underline: $on));
    }

    public function strike(bool $on = true): static
    {
        return $this->withStyle(new Style(strike: $on));
    }

    public function keepTogether(bool $on = true): static
    {
        return $this->withStyle(new Style(keepTogether: $on));
    }

    /**
     * Adds an entry to the PDF's bookmarks panel that jumps to this block.
     * Headings get one automatically; pass false to turn that off.
     *
     * @param int|null $level nesting depth from 1; null keeps a heading's own level, or 1
     */
    public function bookmark(string|false $title, ?int $level = null): static
    {
        if ($level !== null && $level < 1) {
            throw \Papercut\Exception\InvalidValue::for('bookmark level', (string) $level)->because('levels start at 1');
        }
        return $this->withStyle(new Style(bookmark: $title === false ? '' : $title, bookmarkLevel: $level));
    }

    public function keepWithNext(bool $on = true): static
    {
        return $this->withStyle(new Style(keepWithNext: $on));
    }

    public function breakBefore(bool $on = true): static
    {
        return $this->withStyle(new Style(breakBefore: $on));
    }

    public function breakAfter(bool $on = true): static
    {
        return $this->withStyle(new Style(breakAfter: $on));
    }

    public function minLines(int $n): static
    {
        return $this->withStyle(new Style(minLines: $n));
    }

    /**
     * A string is a theme style name resolved at layout time; a Style merges immediately.
     */
    public function apply(Style|string $style): static
    {
        if (is_string($style)) {
            $clone = clone $this;
            $clone->styleName = $style;
            return $clone;
        }
        return $this->withStyle($style);
    }

    /**
     * @param array<string, string|int|float|bool|object> $attrs
     */
    public function attributes(array $attrs): static
    {
        return $this->withStyle(Style::fromAttributes($attrs));
    }

    private static function toColor(Color|string $c): Color
    {
        return $c instanceof Color ? $c : Color::parse($c);
    }
}
