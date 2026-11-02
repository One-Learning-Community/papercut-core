<?php

declare(strict_types=1);

namespace Papercut;

use Papercut\Markup\Note;

/**
 * A node carrying conversion notes, reported by every exporter.
 *
 * @internal Part of Papercut's engine, not its public API. It may change in any release.
 */
interface Annotated extends Node
{
    /** @return list<Note> */
    public function notes(): array;
}
