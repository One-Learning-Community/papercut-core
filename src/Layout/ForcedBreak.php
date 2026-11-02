<?php

declare(strict_types=1);

namespace Papercut\Layout;

/**
 * Why a layout stopped on purpose: a page break ends the page, a column break ends the column.
 *
 * @internal Part of Papercut's engine, not its public API. It may change in any release.
 */
enum ForcedBreak
{
    case Page;
    case Column;
}
