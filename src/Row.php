<?php

declare(strict_types=1);

namespace Papercut;

use Papercut\Style\{Align, Direction};

final class Row
{
    public static function make(Node|Component|string ...$children): View
    {
        return View::make(...$children)->direction(Direction::Row)->align(Align::Stretch);
    }
}
