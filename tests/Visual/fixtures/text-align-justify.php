<?php

declare(strict_types=1);


use Papercut\{Column, Document, Page, Text};

use function Papercut\{mm, pt};

$para = 'Layout engines break paragraphs into lines, and justified text stretches the gaps between words so that both edges of the column line up. A final line stays ragged, and a very long word such as supercalifragilisticexpialidocious must still fit somewhere sensible.';

return Document::make(
    Page::a5()->margin(mm(14))->body(
        Column::make(
            Text::make('Justified')->size(pt(8))->color('slate-500'),
            Text::make($para)->justify(),
            Text::make('Left')->size(pt(8))->color('slate-500'),
            Text::make($para)->left(),
            Text::make('Center')->size(pt(8))->color('slate-500'),
            Text::make($para)->center(),
            Text::make('Right')->size(pt(8))->color('slate-500'),
            Text::make($para)->right(),
        )->gap(pt(5)),
    ),
)->createdAt(new DateTimeImmutable('2026-01-01T00:00:00Z'));
