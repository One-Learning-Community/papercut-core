<?php

declare(strict_types=1);

namespace Papercut;

use Papercut\Style\Direction;

final class Column
{
    public static function make(Node|Component|string ...$children): View
    {
        return View::make(...$children)->direction(Direction::Column);
    }
}
