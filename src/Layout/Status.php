<?php

declare(strict_types=1);

namespace Papercut\Layout;

enum Status
{
    case Complete;
    case Partial;
    case DoesNotFit;
}
