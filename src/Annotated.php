<?php

declare(strict_types=1);

namespace Papercut;

use Papercut\Markup\Note;

/** A node carrying conversion notes, reported by every exporter. */
interface Annotated extends Node
{
    /** @return list<Note> */
    public function notes(): array;
}
