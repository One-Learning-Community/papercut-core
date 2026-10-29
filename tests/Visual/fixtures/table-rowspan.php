<?php

declare(strict_types=1);

use Papercut\Style\TextAlign;
use Papercut\{Column, Document, Page, Table, TableCell, Text};

use function Papercut\{mm, pt};

$long = [];
foreach (range(1, 36) as $i) {
    $long[] = "Line $i";
}

return Document::make(
    Page::a6()->margin(mm(10))->body(
        Column::make(
            Text::h2('Weekly schedule'),
            Table::make()->columns('15mm 1fr 1fr 1fr')
                ->head('Time', 'Mon', 'Tue', 'Wed')
                ->row('9:00', TableCell::make('Workshop')->rowspan(2)->background('amber-100'), 'Reading', 'Review')
                ->row('10:00', 'Writing', 'Lab')
                ->row('11:00', TableCell::make('Lunch')->colspan(3)->align(TextAlign::Center))
                ->row('12:00', TableCell::make('Seminar')->rowspan(3), 'Practice', TableCell::make('Demo')->rowspan(2))
                ->row('13:00', 'Project')
                ->row('14:00', 'Study', 'Quiz'),
            Text::h2('Long spans'),
            Table::make()->columns('25mm 1fr')->head('Group', 'Item')
                ->row(TableCell::make(...array_map(static fn (string $l): Text => Text::make($l), $long))->rowspan(3), 'First')
                ->row('Second')
                ->row('Third')
                ->row('Fourth', 'Fifth'),
        )->gap(pt(6)),
    ),
)->createdAt(new DateTimeImmutable('2026-01-01T00:00:00Z'));
