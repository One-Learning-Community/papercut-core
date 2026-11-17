<?php

declare(strict_types=1);

namespace Quoin;

interface Component
{
    public function render(): Node|Component;
}
