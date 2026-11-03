<?php

declare(strict_types=1);

namespace Papercut\Text;

use Papercut\Backend\{FontHandle, JoinsInPairs};

/** @internal Part of Papercut's engine, not its public API. It may change in any release. */
final class CachedFont implements FontHandle
{
    /** @var array<string, float> unit-size advances keyed by text */
    private array $unit = [];
    /** @var array<string, float> */
    private array $spaced = [];
    /** @var array<string, bool> */
    private array $covered = [];
    private ?bool $joinsInPairs = null;

    public function __construct(private readonly FontHandle $inner)
    {
    }

    public function inner(): FontHandle { return $this->inner; }
    public function id(): string { return $this->inner->id(); }

    /** @phpstan-impure memoizes into $unit/$spaced; repeated calls are not interchangeable with a pure computation */
    public function advance(string $text, float $size, float $letterSpacing = 0.0): float
    {
        if ($letterSpacing !== 0.0) {
            $key = $text . "\0" . $size . "\0" . $letterSpacing;
            return $this->spaced[$key] ??= $this->inner->advance($text, $size, $letterSpacing);
        }
        return ($this->unit[$text] ??= $this->inner->advance($text, 1.0)) * $size;
    }

    public function ascent(float $size): float { return $this->inner->ascent($size); }
    public function descent(float $size): float { return $this->inner->descent($size); }
    /** Whether a merged run can be measured from its joins; false for fonts that don't say. */
    public function joinsInPairs(): bool
    {
        return $this->joinsInPairs ??= $this->inner instanceof JoinsInPairs && $this->inner->joinsInPairs();
    }

    public function covers(string $text): bool { return $this->covered[$text] ??= $this->inner->covers($text); }
}
