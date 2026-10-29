<?php

declare(strict_types=1);

namespace Papercut\Style;

enum WhiteSpace: string
{
    /** Runs of whitespace collapse to one space and newlines are spaces. */
    case Normal = 'normal';
    /** Spaces are kept, newlines break lines, tabs advance to the next stop; long lines still wrap. */
    case PreWrap = 'pre-wrap';
}
