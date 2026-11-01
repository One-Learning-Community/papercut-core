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

    /** A gap of a fixed size, in an absolute unit. */
    public static function fixed(Length $l): self
    {
        return (new self())->size($l);
    }

    /** Sets the gap to a fixed size, keeping the spacer's style. On a spacer, size is the gap, not a font size. */
    public function size(Length $v): static
    {
        $s = clone $this;
        $s->fixed = $v->absolute('spacer size');
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
