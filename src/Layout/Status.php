<?php

declare(strict_types=1);

namespace Quoin\Layout;

/** @internal Engine detail, not public API. It may change in any release. */
enum Status
{
    case Complete;
    case Partial;
    case DoesNotFit;
}
