<?php

declare(strict_types=1);

namespace Papercut;

interface Component
{
    public function render(): Node|Component;
}
