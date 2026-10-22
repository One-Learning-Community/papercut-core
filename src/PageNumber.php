<?php

declare(strict_types=1);

namespace Papercut;

use Papercut\Concerns\HasStyle;

final class PageNumber implements Styled
{
    use HasStyle;

    private function __construct(private readonly string $format)
    {
    }

    public static function make(string $format = '{page}'): self
    {
        return new self($format);
    }

    public function format(): string
    {
        return $this->format;
    }

    public function debugName(): string
    {
        return 'page-number';
    }
}
