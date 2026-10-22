<?php

declare(strict_types=1);

namespace Papercut;

use Papercut\Concerns\HasStyle;

final class TableRow implements Styled
{
    use HasStyle;

    /** @var list<TableCell> */
    private array $cells = [];

    private function __construct()
    {
    }

    public static function make(TableCell|Node|Component|string ...$cells): self
    {
        $row = new self();
        foreach ($cells as $cell) {
            $row->cells[] = $cell instanceof TableCell ? $cell : TableCell::make($cell);
        }
        return $row;
    }

    /**
     * @return list<TableCell>
     */
    public function cells(): array
    {
        return $this->cells;
    }

    public function debugName(): string
    {
        return 'tr';
    }
}
