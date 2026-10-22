<?php

declare(strict_types=1);

namespace Papercut\Style;

enum ImageFit: string
{
    case Contain = 'contain';
    case Cover = 'cover';
    case Fill = 'fill';
}
