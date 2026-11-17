<?php

declare(strict_types=1);

namespace Papercut\Markup;

use Papercut\Export\FidelityLevel;
use Papercut\Math\Math;
use Papercut\Math\Node\Node as MathNode;
use Papercut\Math\NoteLevel;

/**
 * Turns what a formula's input (MathML or TeX) lost, recorded inside a formula tree, into fidelity notes.
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
                // A MathML note names a tag (`<mtable>`); a TeX note, which says where as a byte offset, names a command (`\foo`).
                (str_starts_with($n->where, 'offset ') ? 'math ' . $n->element : 'math <' . $n->element . '>') . ' (' . $n->reason . ')',
                $n->where,
            );
        }
        return $notes;
    }
}
