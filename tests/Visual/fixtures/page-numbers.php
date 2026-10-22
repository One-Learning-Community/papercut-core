<?php

declare(strict_types=1);


use Papercut\{Column, Document, Page, PageBreak, PageNumber, Row, Spacer, Text};

use function Papercut\{mm, pt};

return Document::make(
    Page::a6()->margin(mm(12))
        ->header(Row::make(Text::make('Header')->bold(), Spacer::flex(), PageNumber::make('{page}/{total}')->right()))
        ->body(Column::make(
            Text::h2('First'),
            Text::make('Body of the first page.'),
            PageBreak::make(),
            Text::h2('Second'),
            Text::make('Body of the second page.'),
            PageBreak::make(),
            Text::h2('Third'),
        )->gap(pt(6)))
        ->footer(PageNumber::make('Page {page} of {total}')->center()),
)->createdAt(new DateTimeImmutable('2026-01-01T00:00:00Z'));
