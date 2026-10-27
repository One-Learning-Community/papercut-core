<?php

declare(strict_types=1);

namespace Papercut\Style;

/** The point of a placed box that sits at its x and y: a corner, the middle of a side, or the centre. */
enum Anchor: string
{
    case TopLeft = 'top-left';
    case Top = 'top';
    case TopRight = 'top-right';
    case Left = 'left';
    case Center = 'center';
    case Right = 'right';
    case BottomLeft = 'bottom-left';
    case Bottom = 'bottom';
    case BottomRight = 'bottom-right';

    /** How far across the box the anchor is: 0 at the left, 0.5 in the middle, 1 at the right. */
    public function across(): float
    {
        return match ($this) {
            self::TopLeft, self::Left, self::BottomLeft => 0.0,
            self::Top, self::Center, self::Bottom => 0.5,
            self::TopRight, self::Right, self::BottomRight => 1.0,
        };
    }

    /** How far down the box the anchor is: 0 at the top, 0.5 in the middle, 1 at the bottom. */
    public function down(): float
    {
        return match ($this) {
            self::TopLeft, self::Top, self::TopRight => 0.0,
            self::Left, self::Center, self::Right => 0.5,
            self::BottomLeft, self::Bottom, self::BottomRight => 1.0,
        };
    }
}
