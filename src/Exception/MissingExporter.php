<?php

declare(strict_types=1);

namespace Quoin\Exception;

use Quoin\QuoinException;

final class MissingExporter extends QuoinException
{
    public static function for(string $format, string $package): self
    {
        return new self(sprintf('%s export needs %s: run "composer require %s".', strtoupper($format), $package, $package));
    }
}
