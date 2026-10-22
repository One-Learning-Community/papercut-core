<?php

declare(strict_types=1);

namespace Papercut;

use Papercut\Concerns\HasStyle;
use Papercut\Value\Length;

final class Spacer implements Styled
{
    use HasStyle;

    private ?Length $fixed = null;

    private function __construct()
    {
    }

    public static function size(Length $l): self
    {
        $s = new self();
        $s->fixed = $l->absolute('spacer size');
        return $s;
    }

    public static function flex(float $grow = 1): self
    {
        return (new self())->grow($grow);
    }

    public function fixedSize(): ?Length
    {
        return $this->fixed;
    }

    public function debugName(): string
    {
        return 'spacer';
    }
}
