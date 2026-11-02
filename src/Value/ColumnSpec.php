<?php

declare(strict_types=1);

namespace Papercut\Value;

use Papercut\Exception\InvalidValue;

/** Column count, widths, gaps and rule of a ColumnFlow. Lengths are in points. */
final readonly class ColumnSpec
{
    public const DEFAULT_GAP = 36.0;

    /** Word's limit on columns in a section. */
    public const MAX_COLUMNS = 45;

    /**
     * @param ?list<float> $shares relative column widths, or null for equal columns
     * @param list<float> $gaps count - 1 gaps in points
     */
    public function __construct(
        public int $count,
        public ?array $shares,
        public array $gaps,
        public ?ColumnRule $rule = null,
        public bool $fromMarkup = false,
    ) {
        self::checkCount($count);
        if ($shares !== null && count($shares) !== $count) {
            throw InvalidValue::for('column widths', implode(', ', array_map(InvalidValue::number(...), $shares)))
                ->because(sprintf('expected %d widths', $count));
        }
        if (count($gaps) !== $count - 1) {
            throw InvalidValue::for('column gaps', (string) count($gaps))
                ->because('expected ' . self::gapsText($count - 1));
        }
        foreach ($gaps as $gap) {
            if (!is_finite($gap) || $gap < 0) {
                throw InvalidValue::for('column gap', InvalidValue::number($gap))->because('must not be negative');
            }
        }
        if ($rule !== null && (!is_finite($rule->width) || $rule->width <= 0)) {
            throw InvalidValue::for('column rule width', InvalidValue::number($rule->width))->because('must be greater than zero');
        }
    }

    /** "1 gap", "3 gaps" */
    public static function gapsText(int $n): string
    {
        return $n === 1 ? '1 gap' : sprintf('%d gaps', $n);
    }

    /** @throws InvalidValue outside 1..MAX_COLUMNS */
    public static function checkCount(int $count): void
    {
        if ($count < 1 || $count > self::MAX_COLUMNS) {
            throw InvalidValue::for('column count', (string) $count)->because(sprintf('use 1 to %d columns', self::MAX_COLUMNS));
        }
    }

    public static function equal(int $count): self
    {
        self::checkCount($count);
        return new self($count, null, array_fill(0, max(0, $count - 1), self::DEFAULT_GAP));
    }

    public function withFromMarkup(): self
    {
        return new self($this->count, $this->shares, $this->gaps, $this->rule, true);
    }

    /** Whether the gaps leave room for columns in a flow $width wide. */
    public function fits(float $width): bool
    {
        return array_sum($this->gaps) < $width;
    }

    /**
     * Column boxes across a flow $width wide.
     *
     * @return list<array{x: float, width: float}>
     */
    public function columns(float $width): array
    {
        $space = max(0.0, $width - array_sum($this->gaps));
        $total = $this->shares === null ? (float) $this->count : array_sum($this->shares);
        $out = [];
        $x = 0.0;
        for ($i = 0; $i < $this->count; $i++) {
            $w = $space * ($this->shares[$i] ?? 1.0) / $total;
            $out[] = ['x' => min($x, $width), 'width' => $w];
            $x += $w + ($this->gaps[$i] ?? 0.0);
        }
        return $out;
    }
}
