<?php

declare(strict_types=1);

use Papercut\{Column, Document, Page, Text};
use Papercut\Decorator\{Callout, Card, Field, Section};

use function Papercut\mm;

$filler = static fn (int $from, int $to): array => array_map(static fn (int $i): Text => Text::make("Filler line $i"), range($from, $to));
$lines = static fn (string $what, int $n): array => array_map(static fn (int $i): Text => Text::make("$what line $i"), range(1, $n));

return Document::make(
    Page::a6()->margin(mm(10))->body(
        Column::make(...[
            ...$filler(1, 16),
            Card::make(...$lines('Card body', 14))->title('Card that splits'),
            ...$filler(17, 20),
            Callout::make(...$lines('Callout', 12))->tone('warning')->title('Callout that splits'),
            ...$filler(21, 25),
            Field::make(...$lines('Field', 12))->label('Field that splits'),
            ...$filler(26, 44),
            Section::make(Text::make('Body of the last section.'))->title('Last section')->number('9'),
        ]),
    ),
)->createdAt(new DateTimeImmutable('2026-01-01T00:00:00Z'));
