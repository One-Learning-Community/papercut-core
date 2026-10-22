<?php

declare(strict_types=1);

namespace Papercut\Value;

enum Unit: string
{
    case Pt = 'pt';
    case Px = 'px';
    case Mm = 'mm';
    case Cm = 'cm';
    case In = 'in';

    /** Points per one of this unit. */
    public function points(): float
    {
        return match ($this) {
            self::Pt => 1.0,
            self::Px => 0.75,
            self::Mm => 72 / 25.4,
            self::Cm => 720 / 25.4,
            self::In => 72.0,
        };
    }
}
