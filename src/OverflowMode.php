<?php

declare(strict_types=1);

namespace Quoin;

enum OverflowMode
{
    case Throw;
    case Clip;
}
