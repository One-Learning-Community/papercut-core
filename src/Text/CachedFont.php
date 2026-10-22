<?php

declare(strict_types=1);

namespace Papercut\Text;

use Papercut\Backend\FontHandle;

final class CachedFont implements FontHandle
{
    /** @var array<string, float> unit-size advances keyed by text */
    private array $unit = [];
    /** @var array<string, float> */
    private array $spaced = [];

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
    public function covers(string $text): bool { return $this->inner->covers($text); }
}
