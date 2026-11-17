<?php

declare(strict_types=1);

namespace Quoin;

use Quoin\Style\Direction;

final class Column
{
    public static function make(Node|Component|string ...$children): View
    {
        return View::make(...$children)->direction(Direction::Column);
    }
}
