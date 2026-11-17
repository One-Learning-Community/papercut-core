<?php

declare(strict_types=1);

namespace Quoin\Markup;

enum BlankParagraphs: string
{
    case Drop = 'drop';
    case Keep = 'keep';
}
