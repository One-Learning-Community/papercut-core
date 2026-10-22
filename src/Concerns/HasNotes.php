<?php

declare(strict_types=1);

namespace Papercut\Concerns;

use Papercut\Markup\Note;

trait HasNotes
{
    /** @var list<Note> */
    private array $notes = [];

    /** @return list<Note> */
    public function notes(): array
    {
        return $this->notes;
    }

    public function withNotes(Note ...$notes): static
    {
        $clone = clone $this;
        $clone->notes = [...$this->notes, ...array_values($notes)];
        return $clone;
    }
}
