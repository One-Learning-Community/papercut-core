<?php

declare(strict_types=1);

namespace Quoin;

use Quoin\Concerns\HasStyle;

final class PageBreak implements Styled
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
        return 'page-break';
    }
}
