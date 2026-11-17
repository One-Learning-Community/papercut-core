<?php

declare(strict_types=1);

namespace Quoin\Exception;

use Quoin\QuoinException;

/** The document's shape is wrong, such as a node placed where it cannot work. */
class InvalidStructure extends QuoinException
{
}
