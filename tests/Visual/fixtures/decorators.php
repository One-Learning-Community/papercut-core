<?php

declare(strict_types=1);

use Papercut\{Column, Document, Page, Text};
use Papercut\Decorator\{Badge, Blockquote, Callout, Card, ChoiceList, Field, KeyValueList, Section};

use function Papercut\mm;

return Document::make(
    Page::a4()->margin(mm(15))->body(
        Column::make(
            KeyValueList::make()
                ->row('Respondent', 'Jane Smith')
                ->row('Submitted', '2026-03-02 14:30')
                ->row('Course', 'Introduction to Research Methods'),
            Section::make(
                Field::make(Text::make('I usually start with the library catalogue, then widen to a general search.'))
                    ->label('Comments'),
                Field::make()->label('Anything else?')->placeholder('No response')->lines(2),
            )->title('Search terms')->number('1')->rule(),
            Section::make(
                ChoiceList::checkbox()
                    ->option('Library catalogue', selected: true)
                    ->option('Web search')
                    ->option('Ask a colleague')
                    ->emphasizeSelected(),
                ChoiceList::radio()
                    ->option('Daily')
                    ->option('Weekly', selected: true)
                    ->option('Rarely')
                    ->emphasizeSelected(),
            )->title('How do you search?')->number('2'),
            Callout::make(Text::make('Read each statement before you answer.'))->tone('note')->title('Note'),
            Callout::make(Text::make('Answers cannot be changed once submitted.'))->tone('tip')->title('Tip'),
            Callout::make(Text::make('Your session ends after 30 minutes.'))->tone('warning')->title('Warning'),
            Callout::make(Text::make('Submissions after the deadline are discarded.'))->tone('danger')->title('Danger'),
            Card::make(
                Badge::make('Neutral'),
                Badge::make('Note')->tone('note'),
                Badge::make('Tip')->tone('tip'),
                Badge::make('Warning')->tone('warning'),
                Badge::make('Danger')->tone('danger'),
            )->title('Badges'),
            Blockquote::make(Text::make('Research is formalized curiosity. It is poking and prying with a purpose.'))
                ->cite('Zora Neale Hurston'),
            Text::markup('A paragraph with a <mark>highlighted phrase</mark> in the middle of the sentence.'),
        )->gap(mm(3)),
    ),
)->createdAt(new DateTimeImmutable('2026-01-01T00:00:00Z'));
