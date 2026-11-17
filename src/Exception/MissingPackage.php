<?php

declare(strict_types=1);

namespace Quoin\Exception;

use Quoin\QuoinException;

/** A feature that relies on an optional Composer package which is not installed. */
final class MissingPackage extends QuoinException
{
    /** @param string $feature what needs the package, as the subject of "needs" (or "need", when $plural) */
    public static function for(string $feature, string $package, bool $plural = false): self
    {
        return new self(sprintf('%s %s %s: run "composer require %s".', $feature, $plural ? 'need' : 'needs', $package, $package));
    }
}
