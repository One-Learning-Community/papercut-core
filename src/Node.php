<?php

declare(strict_types=1);

namespace Papercut;

use Papercut\Style\Style;

interface Node
{
    public function debugName(): string;

    public function style(): Style;
}
