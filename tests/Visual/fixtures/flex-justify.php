<?php

declare(strict_types=1);


use Papercut\{Column, View, Document, Page, Row, Spacer, Text};
use Papercut\Style\{Align, Justify};

use function Papercut\{mm, pt};

$box = static fn (string $label, string $bg): Text => Text::make($label)->padding(pt(8))->background($bg)->width(mm(28))->center();
$row = static fn (Justify $j): View => Column::make(
    Text::make($j->value)->size(pt(8))->color('slate-500'),
    Row::make($box('A', 'sky-200'), $box('B', 'emerald-200'), $box('C', 'amber-200'))->justify($j)->border(pt(0.5), 'slate-300'),
)->gap(pt(2));

return Document::make(
    Page::a5()->margin(mm(12))->body(
        Column::make(
            $row(Justify::Start),
            $row(Justify::End),
            $row(Justify::Center),
            $row(Justify::SpaceBetween),
            $row(Justify::SpaceAround),
            $row(Justify::SpaceEvenly),
            Row::make(Text::make('left'), Spacer::flex(), Text::make('right'))->align(Align::Center)->border(pt(0.5), 'slate-300')->padding(pt(4)),
        )->gap(pt(10)),
    ),
)->createdAt(new DateTimeImmutable('2026-01-01T00:00:00Z'));
