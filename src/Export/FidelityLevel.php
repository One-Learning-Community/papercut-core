<?php

declare(strict_types=1);

namespace Papercut\Export;

enum FidelityLevel
{
    case Approximated;
    case Dropped;
}
