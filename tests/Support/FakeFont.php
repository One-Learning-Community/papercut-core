<?php

declare(strict_types=1);

namespace Papercut\Tests\Support;

use Papercut\Backend\FontHandle;

final class FakeFont implements FontHandle
{
    public int $advanceCalls = 0;
    /** Total characters measured by advance(). */
    public int $advancedChars = 0;
    /** Calls to ascent() and descent(). */
    public int $metricCalls = 0;

    /** $kerning: the pair "o A" is 1pt (at 10pt) tighter than its glyphs, like a real kerned font. */
    public function __construct(private readonly string $id = 'fake', private readonly bool $kerning = false, private readonly bool $coversAll = false)
    {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function advance(string $text, float $size, float $letterSpacing = 0.0): float
    {
        $this->advanceCalls++;
        $n = mb_strlen($text, 'UTF-8');
        $this->advancedChars += $n;
        $kern = $this->kerning ? substr_count($text, 'o A') * 0.1 * $size : 0.0;
        return $n * 0.5 * $size + $n * $letterSpacing - $kern;
    }

    public function ascent(float $size): float
    {
        $this->metricCalls++;
        return 0.8 * $size;
    }

    public function descent(float $size): float
    {
        $this->metricCalls++;
        return 0.2 * $size;
    }

    public function covers(string $text): bool
    {
        if ($this->coversAll) {
            return true;
        }
        foreach (mb_str_split($text, 1, 'UTF-8') as $ch) {
            if (mb_ord($ch, 'UTF-8') >= 0x3000) {
                return false;
            }
        }
        return true;
    }
}
