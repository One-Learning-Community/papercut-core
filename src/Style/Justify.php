<?php

declare(strict_types=1);

namespace Quoin\Style;

enum Justify: string
{
    case Start = 'start';
    case End = 'end';
    case Center = 'center';
    case SpaceBetween = 'space-between';
    case SpaceAround = 'space-around';
    case SpaceEvenly = 'space-evenly';
}
