<?php

declare(strict_types=1);

use Papercut\{Column, Document, Page, Row, Text};
use Papercut\Decorator\{Badge, Card, Field, Section, Signature, Stat, Totals};

use function Papercut\in;
use function Papercut\pt;

return Document::make(
    Page::letter()->margin(in(0.75))->body(
        Column::make(
            Row::make(
                Stat::make('$48,200')->label('Revenue')->note('Up 12% on Q2')->grow(1),
                Stat::make('1,204')->label('Orders')->note('38 refunded')->grow(1),
                Stat::make('4.7')->label('Rating')->grow(1),
            )->gap(pt(12)),
            Totals::make()
                ->row('Subtotal', '$1,000.00')
                ->row('Tax (8.25%)', '$82.50')
                ->total('Total due', '$1,082.50'),
            Section::make(
                Row::make(
                    Field::make('Jane Smith')->label('Name')->variant('line')->grow(1),
                    Field::make()->label('Email')->lines(1)->variant('line')->grow(1),
                )->gap(pt(16)),
            )->title('Large section with line fields')->number('3')->variant('large'),
            Card::make(
                Row::make(
                    Badge::make('Neutral')->variant('outline'),
                    Badge::make('Note')->tone('note')->variant('outline'),
                    Badge::make('Tip')->tone('tip')->variant('outline'),
                    Badge::make('Warning')->tone('warning')->variant('outline'),
                    Badge::make('Danger')->tone('danger')->variant('outline'),
                )->gap(pt(6)),
            )->title('Flat card with outline badges')->variant('flat'),
            Row::make(
                Signature::make(Text::make('Jane Smith')->italic()->size(pt(16)))
                    ->name('Jane Smith')->role('Director')->date('12 March 2026'),
                Signature::make()->name('Witness')->role('Name and date'),
            )->gap(pt(36)),
        )->gap(pt(16)),
    ),
)->createdAt(new DateTimeImmutable('2026-01-01T00:00:00Z'));
