<?php

declare(strict_types=1);

namespace Papercut;

use Papercut\Concerns\HasStyle;
use Papercut\Exception\InvalidValue;

/** A table of contents built from the document's headings. Only works in a page body. */
final class Toc implements Styled
{
    use HasStyle;

    private int $from = 1;
    private int $to = 2;
    private ?string $title = null;
    private bool $leaders = true;

    private function __construct()
    {
    }

    public static function make(): self
    {
        return new self();
    }

    /** Lists headings from level $from to level $to, both between 1 and 4. */
    public function levels(int $from, int $to): static
    {
        if ($from < 1 || $to > 4 || $from > $to) {
            throw InvalidValue::for('table of contents levels', $from . '-' . $to)->because('levels run from 1 to 4, and the first cannot pass the last');
        }
        $c = clone $this;
        $c->from = $from;
        $c->to = $to;
        return $c;
    }

    public function title(?string $title): static
    {
        $c = clone $this;
        $c->title = $title === null || trim($title) === '' ? null : $title;
        return $c;
    }

    public function leaders(bool $on = true): static
    {
        $c = clone $this;
        $c->leaders = $on;
        return $c;
    }

    public function fromLevel(): int
    {
        return $this->from;
    }

    public function toLevel(): int
    {
        return $this->to;
    }

    public function titleText(): ?string
    {
        return $this->title;
    }

    public function hasLeaders(): bool
    {
        return $this->leaders;
    }

    public function debugName(): string
    {
        return 'toc';
    }
}
