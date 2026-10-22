<?php

declare(strict_types=1);

namespace Papercut\Backend;

interface ImageHandle
{
    public function id(): string;

    public function widthPx(): int;

    public function heightPx(): int;
}
