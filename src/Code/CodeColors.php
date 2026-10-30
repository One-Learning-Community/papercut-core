<?php

declare(strict_types=1);

namespace Papercut\Code;

use Papercut\Value\Color;

/** Muted chrome colours for a code block (label, border, strip bar, caption rule), chosen by the background's brightness. */
final readonly class CodeColors
{
    private function __construct(
        public Color $label,
        public Color $border,
        public Color $bar,
        public Color $rule,
    ) {
    }

    public static function for(Color $background): self
    {
        return self::isLight($background)
            ? new self(Color::hex('#94a3b8'), Color::hex('#e2e8f0'), Color::hex('#f1f5f9'), Color::hex('#cbd5e1'))
            : new self(Color::hex('#64748b'), Color::hex('#334155'), Color::hex('#1e293b'), Color::hex('#475569'));
    }

    /** YIQ brightness, (299R + 587G + 114B) / 1000, of 128 or more. */
    public static function isLight(Color $background): bool
    {
        return (299 * $background->r + 587 * $background->g + 114 * $background->b) >= 128000;
    }
}
