<?php

declare(strict_types=1);

namespace Papercut;

use Papercut\Concerns\HasStyle;
use Papercut\Style\TextAlign;

final class TableCell implements Styled
{
    use HasStyle;

    /** @var list<Node|Component> */
    private array $children = [];
    private int $span = 1;
    private int $rowspan = 1;
    private bool $header = false;

    private function __construct()
    {
    }

    public static function make(Node|Component|string ...$children): self
    {
        $cell = new self();
        $cell->children = View::normalize($children);
        return $cell;
    }

    /**
     * Creates a header (th) cell. (Named th() because header() is the instance predicate.)
     */
    public static function th(Node|Component|string ...$children): self
    {
        $cell = self::make(...$children);
        $cell->header = true;
        return $cell;
    }

    public function colspan(int $n): self
    {
        if ($n < 1) {
            throw new \InvalidArgumentException('colspan must be at least 1.');
        }
        $clone = clone $this;
        $clone->span = $n;
        return $clone;
    }

    /** How many columns the cell spans (set with colspan()). */
    public function span(): int
    {
        return $this->span;
    }

    /** How many columns the cell spans: the same as span(), named to pair with rowsSpanned(). */
    public function colsSpanned(): int
    {
        return $this->span;
    }

    public function rowspan(int $n): self
    {
        if ($n < 1) {
            throw new \InvalidArgumentException('rowspan must be at least 1.');
        }
        $clone = clone $this;
        $clone->rowspan = $n;
        return $clone;
    }

    /** How many rows the cell spans (set with rowspan()). */
    public function rowsSpanned(): int
    {
        return $this->rowspan;
    }

    /**
     * @return list<Node|Component>
     */
    public function children(): array
    {
        return $this->children;
    }

    public function header(): bool
    {
        return $this->header;
    }

    /** @throws \Papercut\Exception\InvalidValue for a string that is not a text alignment */
    public function align(TextAlign|string $a): self
    {
        return $this->textAlign($a);
    }

    public function asHeader(): self
    {
        $clone = clone $this;
        $clone->header = true;
        return $clone;
    }

    public function debugName(): string
    {
        return $this->header ? 'th' : 'td';
    }
}
