<?php

declare(strict_types=1);

namespace Papercut\Support;

/**
 * "Did you mean" suggestions for unknown names.
 *
 * @internal
 */
final class Suggest
{
    /**
     * The candidate closest to $input by Levenshtein distance, at most $max
     * edits away; ties go to the alphabetically first candidate.
     *
     * @param iterable<int|string, string> $candidates
     */
    public static function closest(string $input, iterable $candidates, int $max = 2): ?string
    {
        $best = null;
        $bestDistance = $max + 1;
        foreach ($candidates as $candidate) {
            $candidate = (string) $candidate;
            $d = levenshtein($input, $candidate);
            if ($d < $bestDistance || ($d === $bestDistance && $best !== null && strcmp($candidate, $best) < 0)) {
                $best = $candidate;
                $bestDistance = $d;
            }
        }
        return $best;
    }
}
