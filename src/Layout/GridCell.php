<?php

declare(strict_types=1);

namespace Quoin\Layout;

use Quoin\TableCell;

/**
 * One cell's resolved position in a table grid. Rows are indexed across the
 * header rows followed by the body rows; $rowspan is already clamped to its section.
 *
 * @internal Engine detail, not public API. It may change in any release.
 */
final readonly class GridCell
{
    public function __construct(
        public TableCell $cell,
        public int $index,
        public int $row,
        public int $col,
        public int $colspan,
        public int $rowspan,
    ) {
    }
}
