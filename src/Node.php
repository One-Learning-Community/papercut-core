<?php

declare(strict_types=1);

namespace Quoin;

use Quoin\Style\Style;

interface Node
{
    public function debugName(): string;

    public function style(): Style;
}
