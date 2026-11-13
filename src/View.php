<?php

declare(strict_types=1);

namespace Papercut;

use Papercut\Concerns\{HasNotes, HasStyle};
use Papercut\Exception\InvalidValue;
use Papercut\Style\{Align, Direction, Justify, Style};
use Papercut\Support\{EnumValue, Suggest};
use Papercut\Value\{Color, ColumnRule, ColumnSpec, Length};

final class View implements Styled, Annotated
{
    use HasStyle;
    use HasNotes;

    /** @var list<Node|Component> */
    private array $children = [];
    private bool $explicitDirection = false;
    private bool $badge = false;
    private string $htmlTag = 'div';
    private ?ColumnSpec $columns = null;
    private bool $columnCountExplicit = false;
    /** @var ?list<float> gaps given before the column count was chosen */
    private ?array $pendingGaps = null;

    private const HTML_TAGS = ['div', 'section', 'aside', 'blockquote', 'header', 'footer', 'figure'];

    private function __construct()
    {
    }

    public static function make(Node|Component|string ...$children): self
    {
        $view = new self();
        static $column = null; // Style is immutable, so every view starts from one shared column style
        $view->style = $column ??= new Style(direction: Direction::Column);
        $view->children = self::normalize($children);
        return $view;
    }

    /**
     * Wraps string children in Text nodes.
     *
     * @internal
     * @param array<Node|Component|string> $children
     * @return list<Node|Component>
     */
    public static function normalize(array $children): array
    {
        $out = [];
        foreach ($children as $child) {
            $out[] = is_string($child) ? Text::make($child) : $child;
        }
        return $out;
    }

    /**
     * @return list<Node|Component>
     */
    public function children(): array
    {
        return $this->children;
    }

    public function withChildren(Node|Component|string ...$children): self
    {
        $clone = clone $this;
        $clone->children = self::normalize($children);
        return $clone;
    }

    /**
     * Marks this view as a block badge, for outputs that draw badges some other way (Word writes a shaded run).
     *
     * @internal
     */
    public function badgeHint(): self
    {
        $clone = clone $this;
        $clone->badge = true;
        return $clone;
    }

    /** @internal */
    public function isBadge(): bool
    {
        return $this->badge;
    }

    /**
     * Hints the HTML element this view exports as; PDF ignores it.
     */
    public function htmlTag(string $tag): self
    {
        $normalized = strtolower($tag);
        if (!in_array($normalized, self::HTML_TAGS, true)) {
            throw InvalidValue::for('html element', $tag, Suggest::closest($normalized, self::HTML_TAGS));
        }
        $clone = clone $this;
        $clone->htmlTag = $normalized;
        return $clone;
    }

    public function htmlElement(): string
    {
        return $this->htmlTag;
    }

    public function debugName(): string
    {
        if ($this->columns !== null) {
            return 'column-flow';
        }
        if (!$this->explicitDirection) {
            return 'view';
        }
        return $this->style()->direction === Direction::Row ? 'row' : 'column';
    }

    /** @throws InvalidValue when gaps given before the column count don't fit it */
    public function columnSpec(): ?ColumnSpec
    {
        if ($this->pendingGaps !== null && $this->columns !== null) {
            self::gapsFor($this->pendingGaps, $this->columns->count);
        }
        return $this->columns;
    }

    /** @internal */
    public function withColumnSpec(ColumnSpec $spec): self
    {
        $clone = clone $this;
        $clone->columns = $spec;
        return $clone;
    }

    /** Makes this view a column flow with $count equal columns. */
    public function columns(int $count): self
    {
        ColumnSpec::checkCount($count);
        $spec = $this->columns ?? ColumnSpec::equal($count);
        $gaps = $spec->gaps;
        if ($this->pendingGaps !== null) {
            $gaps = self::gapsFor($this->pendingGaps, $count);
        } elseif (count($gaps) !== $count - 1) {
            $gap = $gaps[0] ?? ColumnSpec::DEFAULT_GAP;
            $gaps = $count > 1 && count(array_unique($gaps)) > 1 ? $gaps : array_fill(0, $count - 1, $gap);
        }
        $shares = $spec->shares !== null && count($spec->shares) === $count ? $spec->shares : null;
        $clone = $this->withColumnSpec(new ColumnSpec($count, $shares, $gaps, $spec->rule, $spec->fromMarkup));
        $clone->columnCountExplicit = true;
        $clone->pendingGaps = null;
        return $clone;
    }

    /**
     * One gap for every space, or exactly $count - 1 gaps.
     *
     * @param list<float> $points
     * @return list<float>
     */
    private static function gapsFor(array $points, int $count): array
    {
        $spaces = $count - 1;
        if (count($points) === 1) {
            return array_fill(0, $spaces, $points[0]);
        }
        if (count($points) !== $spaces) {
            throw InvalidValue::for('column gaps', (string) count($points))->because(
                $spaces === 1 ? 'expected 1 gap' : sprintf('expected 1 gap or %s', ColumnSpec::gapsText($spaces)),
            );
        }
        return $points;
    }

    /** Relative column widths; the number of shares sets the column count. */
    public function columnWidths(float|int ...$shares): self
    {
        if ($shares === []) {
            throw InvalidValue::for('column widths', '')->because('give at least one width');
        }
        foreach ($shares as $share) {
            if (!is_finite((float) $share) || $share <= 0) {
                throw InvalidValue::for('column width', InvalidValue::number($share))->because('must be greater than zero');
            }
        }
        ColumnSpec::checkCount(count($shares));
        $flow = $this->columns(count($shares));
        $spec = $flow->columns ?? throw new \LogicException('columns() always sets a spec.');
        return $flow->withColumnSpec(new ColumnSpec(
            $spec->count,
            array_values(array_map(floatval(...), $shares)),
            $spec->gaps,
            $spec->rule,
            $spec->fromMarkup,
        ));
    }

    /** One gap for every space between columns, or exactly count - 1 gaps. */
    public function columnGap(Length ...$gaps): self
    {
        $spec = $this->columns ?? ColumnSpec::equal(2);
        $points = array_values(array_map(static fn(Length $g): float => $g->absolute('column gap')->points, $gaps));
        if (!$this->columnCountExplicit) {
            $clone = $this->withColumnSpec($spec);
            $clone->pendingGaps = $points;
            if (count($points) === 1 || count($points) === $spec->count - 1) {
                $clone->columns = new ColumnSpec($spec->count, $spec->shares, self::gapsFor($points, $spec->count), $spec->rule, $spec->fromMarkup);
            }
            return $clone;
        }
        $points = self::gapsFor($points, $spec->count);
        return $this->withColumnSpec(new ColumnSpec($spec->count, $spec->shares, $points, $spec->rule, $spec->fromMarkup));
    }

    /** A line between the columns; 0.5pt black unless given. */
    public function columnRule(?Length $width = null, ?Color $color = null): self
    {
        $spec = $this->columns ?? ColumnSpec::equal(2);
        $rule = new ColumnRule($width?->absolute('column rule width')->points ?? 0.5, $color ?? Color::black());
        return $this->withColumnSpec(new ColumnSpec($spec->count, $spec->shares, $spec->gaps, $rule, $spec->fromMarkup));
    }

    /** @throws InvalidValue for a string that is not a direction */
    public function direction(Direction|string $d): self
    {
        $d = EnumValue::from(Direction::class, $d);
        $clone = $this->withStyle(new Style(direction: $d));
        $clone->explicitDirection = true;
        return $clone;
    }

    public function gap(Length $g): self
    {
        return $this->withStyle(new Style(gap: $g));
    }

    /** @throws InvalidValue for a string that is not a justify option */
    public function justify(Justify|string $j): self
    {
        $j = EnumValue::from(Justify::class, $j);
        return $this->withStyle(new Style(justify: $j));
    }

    /** @throws InvalidValue for a string that is not an alignment */
    public function align(Align|string $a): self
    {
        $a = EnumValue::from(Align::class, $a);
        return $this->withStyle(new Style(alignItems: $a));
    }

    public function wrap(bool $on = true): self
    {
        return $this->withStyle(new Style(wrap: $on));
    }
}
