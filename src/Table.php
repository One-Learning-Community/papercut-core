<?php

declare(strict_types=1);

namespace Quoin;

use Quoin\Concerns\HasStyle;
use Quoin\Exception\InvalidValue;
use Quoin\Value\{Auto, Fr, Length, Percent, TrackList};

final class Table implements Styled
{
    use HasStyle;

    private ?TrackList $trackList = null;
    /** @var list<TableRow> */
    private array $headerRows = [];
    /** @var list<TableRow> */
    private array $bodyRows = [];
    private bool $repeatHeader = true;
    private bool $fillWidth = false;
    private bool $fitColumns = false;

    private function __construct()
    {
    }

    public static function make(): self
    {
        return new self();
    }

    public function columns(TrackList|string|Length|Percent|Fr|Auto ...$tracks): self
    {
        $clone = clone $this;
        try {
            if (count($tracks) === 1 && $tracks[0] instanceof TrackList) {
                $clone->trackList = $tracks[0];
            } elseif (count($tracks) === 1 && is_string($tracks[0])) {
                $clone->trackList = TrackList::parse($tracks[0]);
            } else {
                $typed = [];
                foreach ($tracks as $t) {
                    if ($t instanceof TrackList || is_string($t)) {
                        throw InvalidValue::for('columns()', get_debug_type($t), 'one string or TrackList, or typed tracks, not a mix')->withContext('Table::columns()');
                    }
                    $typed[] = $t;
                }
                $clone->trackList = TrackList::of(...$typed);
            }
        } catch (InvalidValue $e) {
            throw $e->withContext('Table::columns()');
        }
        return $clone;
    }

    public function trackList(): ?TrackList
    {
        return $this->trackList;
    }

    public function head(TableCell|Node|Component|string ...$cells): self
    {
        if ($cells === []) {
            throw new \InvalidArgumentException('Table::head() needs at least one cell.');
        }
        $header = [];
        foreach ($cells as $cell) {
            $header[] = $cell instanceof TableCell
                ? ($cell->header() ? $cell : $cell->asHeader())
                : TableCell::th($cell);
        }
        return $this->headerRow(TableRow::make(...$header));
    }

    public function headerRow(TableRow $r): self
    {
        $clone = clone $this;
        $clone->headerRows[] = $r;
        return $clone;
    }

    /** Adds a body row made of these cells; use bodyRow() for a styled TableRow. */
    public function row(TableCell|Node|Component|string ...$cells): self
    {
        foreach ($cells as $cell) {
            if ($cell instanceof TableRow) {
                throw new \InvalidArgumentException('Use Table::bodyRow() to add a TableRow.');
            }
        }
        return $this->bodyRow(TableRow::make(...$cells));
    }

    /** Adds a body row as is, keeping its row style (mirrors headerRow()). */
    public function bodyRow(TableRow $r): self
    {
        $clone = clone $this;
        $clone->bodyRows[] = $r;
        return $clone;
    }

    /**
     * @param iterable<mixed> $items
     * @param callable(mixed, int): iterable<mixed, TableCell|Node|Component|string|int|float> $map
     */
    public function rows(iterable $items, callable $map): self
    {
        $clone = $this;
        $i = 0;
        foreach ($items as $item) {
            $cells = [];
            $mapped = $map($item, $i);
            if (!is_iterable($mapped)) { // @phpstan-ignore function.alreadyNarrowedType
                throw InvalidValue::for('rows() callback result', get_debug_type($mapped))
                    ->withContext('Table::rows()')
                    ->because('the callback must return a list of cells, such as fn ($item) => [$item->name, $item->total]; use bodyRow() to add a TableRow');
            }
            $n = 0;
            foreach ($mapped as $cell) {
                if (is_float($cell) && !is_finite($cell)) {
                    throw InvalidValue::for('cell value', InvalidValue::number($cell))
                        ->withContext('Table::rows() cell ' . $n . ' of row ' . $i)
                        ->because('a cell must be text, a number that is finite, or a node');
                }
                $cells[] = is_int($cell) || is_float($cell) ? (string) $cell : $cell;
                $n++;
            }
            $clone = $clone->row(...$cells);
            $i++;
        }
        return $clone;
    }

    public function repeatHeader(bool $on = true): self
    {
        $clone = clone $this;
        $clone->repeatHeader = $on;
        return $clone;
    }

    public function repeatsHeader(): bool
    {
        return $this->repeatHeader;
    }

    /** Gives width left over after sizing the columns to the auto columns (when no fr column takes it). */
    public function fillWidth(bool $on = true): self
    {
        $clone = clone $this;
        $clone->fillWidth = $on;
        return $clone;
    }

    public function fillsWidth(): bool
    {
        return $this->fillWidth;
    }

    /**
     * Scales Length columns down, proportionally, when they and the other columns' minimum widths exceed the width
     * available, instead of overflowing it. Set by the markup converter for tables pasted from elsewhere.
     *
     * @internal
     */
    public function fitColumns(bool $on = true): self
    {
        $clone = clone $this;
        $clone->fitColumns = $on;
        return $clone;
    }

    /** @internal */
    public function fitsColumns(): bool
    {
        return $this->fitColumns;
    }

    /**
     * @return list<TableRow>
     */
    public function headerRows(): array
    {
        return $this->headerRows;
    }

    /**
     * @return list<TableRow>
     */
    public function bodyRows(): array
    {
        return $this->bodyRows;
    }

    public function debugName(): string
    {
        return 'table';
    }
}
