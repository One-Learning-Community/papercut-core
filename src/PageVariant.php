<?php

declare(strict_types=1);

namespace Papercut;

/** Which header or footer a page uses: the default, the first page of a run, or even-numbered pages. */
enum PageVariant: string
{
    case Default = 'default';
    case First = 'first';
    case Even = 'even';
}
