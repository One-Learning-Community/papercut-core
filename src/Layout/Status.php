<?php

declare(strict_types=1);

namespace Papercut\Layout;

/** @internal Part of Papercut's engine, not its public API. It may change in any release. */
enum Status
{
    case Complete;
    case Partial;
    case DoesNotFit;
}
