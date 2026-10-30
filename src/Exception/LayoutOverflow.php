<?php

declare(strict_types=1);

namespace Papercut\Exception;

use Papercut\PapercutException;

final class LayoutOverflow extends PapercutException
{
    public function __construct(
        public readonly string $path,
        public readonly string $reason,
        ?string $message = null,
    ) {
        parent::__construct($message ?? sprintf('Content at "%s" cannot fit on an empty page: %s.', $path, $reason));
    }

    /** Content wider than its box, even after shrinking what can shrink. */
    public static function tooWide(string $path, string $reason): self
    {
        return new self($path, $reason, sprintf('Content at "%s" is too wide for its box: %s.', $path, $reason));
    }
}
