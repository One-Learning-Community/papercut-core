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

    public function span(): int
    {
        return $this->span;
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

    public function align(TextAlign $a): self
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
