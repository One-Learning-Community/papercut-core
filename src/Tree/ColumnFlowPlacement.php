<?php

declare(strict_types=1);

namespace Papercut\Tree;

/**
 * Where a ColumnFlow sits, which decides whether real columns are possible there.
 *
 * @internal Part of Papercut's engine, not its public API. It may change in any release.
 */
enum ColumnFlowPlacement
{
    /** On the page body, under nothing but plain wrappers. */
    case OnPage;
    /** Inside a box: a View with its own border, background, padding, size or alignment. */
    case InBox;
    case InCell;
    case InRow;
    case InFlow;
    /** In a header, footer, background, overlay or template. */
    case OutsideBody;

    /** True where a flow can't work at all: a table cell, a flex row, another flow, or outside the body. */
    public function isBlocked(): bool
    {
        return match ($this) {
            self::InCell, self::InRow, self::InFlow, self::OutsideBody => true,
            self::OnPage, self::InBox => false,
        };
    }
}
