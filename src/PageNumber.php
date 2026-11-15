<?php

declare(strict_types=1);

namespace Papercut;

use Papercut\Concerns\HasStyle;
use Papercut\Support\Utf8;

final class PageNumber implements Styled
{
    use HasStyle;

    private function __construct(private readonly string $format)
    {
    }

    public static function make(string $format = '{page}'): self
    {
        return new self(Utf8::require($format, 'page number format'));
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
