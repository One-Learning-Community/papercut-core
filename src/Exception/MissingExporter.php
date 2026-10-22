<?php

declare(strict_types=1);

namespace Papercut\Exception;

use Papercut\PapercutException;

final class MissingExporter extends PapercutException
{
    public static function for(string $format, string $package): self
    {
        return new self(sprintf('%s export needs %s: run "composer require %s".', strtoupper($format), $package, $package));
    }
}
