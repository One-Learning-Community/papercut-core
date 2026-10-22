<?php

declare(strict_types=1);

namespace Papercut;

use Papercut\Concerns\HasStyle;

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
