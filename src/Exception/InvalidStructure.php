<?php

declare(strict_types=1);

namespace Papercut\Exception;

use Papercut\PapercutException;

/** The document's shape is wrong, such as a node placed where it cannot work. */
class InvalidStructure extends PapercutException
{
}
