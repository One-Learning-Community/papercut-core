<?php

declare(strict_types=1);


use Papercut\{Column, Document, Page, Text};

use function Papercut\{mm, pt};

$filler = static fn (int $n): array => array_map(static fn (int $i): Text => Text::make("Filler line $i"), range(1, $n));

return Document::make(
    Page::a6()->margin(mm(10))->body(
        Column::make(...[
            ...$filler(22),
            Column::make(
                Text::make('Kept together: heading')->bold(),
                Text::make('This block does not fit in the space left,'),
                Text::make('so it moves whole to page two.'),
                Text::make('Last line of the block.'),
            )->keepTogether()->padding(pt(4))->background('amber-100'),
            Text::make('Tail paragraph after the block.'),
        ]),
    ),
)->createdAt(new DateTimeImmutable('2026-01-01T00:00:00Z'));
