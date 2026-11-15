<?php

declare(strict_types=1);

namespace Papercut\Markup;

use Papercut\Export\FidelityLevel;
use Papercut\Math\Math;
use Papercut\Math\Node\Node as MathNode;
use Papercut\Math\NoteLevel;

/**
 * Turns what MathML input lost, recorded inside a formula tree, into fidelity notes.
 *
 * @internal Part of Papercut's engine, not its public API. It may change in any release.
 */
final class MathNotes
{
    /** @return list<Note> */
    public static function of(MathNode $root): array
    {
        $notes = [];
        foreach (Math::notes($root) as $n) {
            $notes[] = new Note(
                match ($n->level) {
                    NoteLevel::Dropped => FidelityLevel::Dropped,
                    NoteLevel::Approximated => FidelityLevel::Approximated,
                },
                'math <' . $n->element . '> (' . $n->reason . ')',
                $n->where,
            );
        }
        return $notes;
    }
}
