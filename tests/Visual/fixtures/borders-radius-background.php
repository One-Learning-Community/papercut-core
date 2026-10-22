<?php

declare(strict_types=1);


use Papercut\{Column, Document, Page, Row, Text};
use Papercut\Value\Edges;

use function Papercut\{mm, pt};

return Document::make(
    Page::a5()->margin(mm(12))->body(
        Column::make(
            Text::make('Plain border')->padding(pt(10))->border(pt(1), 'slate-700'),
            Text::make('Radius and background')->padding(pt(10))->background('indigo-100')->border(pt(1.5), 'indigo-600')->radius(pt(10)),
            Text::make('Background only')->padding(pt(10))->background('amber-100'),
            Text::make('Bottom rule')->padding(pt(6))->borderWidth(Edges::of(pt(0), pt(0), pt(2), pt(0)))->borderColor('rose-500'),
            Text::make('Half opacity')->padding(pt(10))->background('emerald-500')->color('white')->opacity(0.5)->radius(pt(4)),
            Row::make(
                Text::make('One')->padding(pt(8))->background('sky-100')->radius(pt(16))->grow(),
                Text::make('Two')->padding(pt(8))->background('sky-200')->border(pt(1), 'sky-600')->grow(),
            )->gap(pt(8)),
        )->gap(pt(10)),
    ),
)->createdAt(new DateTimeImmutable('2026-01-01T00:00:00Z'));
