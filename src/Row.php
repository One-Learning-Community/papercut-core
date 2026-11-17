<?php

declare(strict_types=1);

namespace Quoin;

use Quoin\Style\{Align, Direction};

final class Row
{
    public static function make(Node|Component|string ...$children): View
    {
        return View::make(...$children)->direction(Direction::Row)->align(Align::Stretch);
    }
}
