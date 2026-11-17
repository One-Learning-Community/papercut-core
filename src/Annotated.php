<?php

declare(strict_types=1);

namespace Quoin;

use Quoin\Markup\Note;

/**
 * A node carrying conversion notes, reported by every exporter.
 *
 * @internal Engine detail, not public API. It may change in any release.
 */
interface Annotated extends Node
{
    /** @return list<Note> */
    public function notes(): array;
}
