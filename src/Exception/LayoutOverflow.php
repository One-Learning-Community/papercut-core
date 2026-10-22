<?php

declare(strict_types=1);

namespace Papercut\Exception;

use Papercut\PapercutException;

final class LayoutOverflow extends PapercutException
{
    public function __construct(
        public readonly string $path,
        public readonly string $reason,
    ) {
        parent::__construct(sprintf('Content at "%s" cannot fit on an empty page: %s.', $path, $reason));
    }
}
