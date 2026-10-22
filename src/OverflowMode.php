<?php

declare(strict_types=1);

namespace Papercut;

enum OverflowMode
{
    case Throw;
    case Clip;
}
