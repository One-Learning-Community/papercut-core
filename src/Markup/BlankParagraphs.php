<?php

declare(strict_types=1);

namespace Papercut\Markup;

enum BlankParagraphs: string
{
    case Drop = 'drop';
    case Keep = 'keep';
}
