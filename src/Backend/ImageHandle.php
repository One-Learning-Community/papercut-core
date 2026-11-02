<?php

declare(strict_types=1);

namespace Papercut\Backend;

/** @internal Part of Papercut's PDF writer. Custom backends aren't supported, and it may change in any release. */
interface ImageHandle
{
    public function id(): string;

    public function widthPx(): int;

    public function heightPx(): int;
}
