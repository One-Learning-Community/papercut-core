<?php

declare(strict_types=1);

namespace Papercut;

use Papercut\Concerns\HasStyle;

/** Ends the current column of a ColumnFlow; the next content starts in the next column. */
final class ColumnBreak implements Styled
{
    use HasStyle;

    private function __construct()
    {
    }

    public static function make(): self
    {
        return new self();
    }

    public function debugName(): string
    {
        return 'column-break';
    }
}
