<?php

declare(strict_types=1);

use Papercut\{Document, Page, Text, Toc};

// Generic, made-up content: a cover page, a contents list of levels 1 and 2 with dot leaders and links,
// and a few sections across later pages.
$body = static fn (string $what): Text => Text::make("A short paragraph about $what, here only to give the section some weight on the page.");

return Document::make(
    Page::a5()->body(Text::h1('Field Guide'), Text::make('A made-up handbook')),
    Page::a5()->body(Toc::make()->title('Contents')->levels(1, 2)),
    Page::a5()->body(
        Text::h1('Getting started'),
        $body('setting out'),
        Text::h2('Packing the basics'),
        $body('what to carry'),
        Text::h2('Choosing a route'),
        $body('maps and markers'),
    ),
    Page::a5()->body(
        Text::h1('On the trail'),
        $body('pace and rest'),
        Text::h2('Weather and shelter'),
        $body('reading the sky'),
        Text::h1('Coming home'),
        $body('the last mile'),
    ),
);
