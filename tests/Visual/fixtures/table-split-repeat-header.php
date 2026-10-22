<?php

declare(strict_types=1);


use Papercut\{Column, Document, Page, Table, Text};

use function Papercut\{mm, pt};

$rows = array_map(static fn (int $i): array => ["Item $i", (string) ($i * 3 % 7 + 1), 'Note for row ' . $i], range(1, 40));

return Document::make(
    Page::a6()->margin(mm(10))->body(
        Column::make(
            Text::h2('Split table'),
            Table::make()->columns('2fr 15mm 3fr')->head('Name', 'Qty', 'Notes')
                ->rows($rows, static fn (array $r): array => [$r[0], Text::make($r[1])->right(), $r[2]]),
        )->gap(pt(6)),
    ),
)->createdAt(new DateTimeImmutable('2026-01-01T00:00:00Z'));
