<?php

declare(strict_types=1);

namespace Papercut\Exception;

use InvalidArgumentException;

/** A decorator variant word that collides with one of the decorator's own part names. */
final class InvalidVariant extends InvalidArgumentException
{
}
