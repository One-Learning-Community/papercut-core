<?php

declare(strict_types=1);

namespace Papercut;

use Papercut\Value\ColumnSpec;

/** A box whose content flows down one column, then the next, like Word's section columns. */
final class ColumnFlow
{
    public static function make(Node|Component|string ...$children): View
    {
        return View::make(...$children)->withColumnSpec(ColumnSpec::equal(2));
    }
}
