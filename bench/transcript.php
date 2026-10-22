<?php

declare(strict_types=1);

/**
 * The benchmark document (spec §3.5): a transcript with a header row, 300 body
 * rows in 3 columns, a repeating header and a "Page n of N" footer. Exactly
 * 10 pages; tests/Feature/BenchmarkDocumentTest.php pins that.
 *
 * Returns the Document. Deterministic: no clock, no randomness.
 */

use Papercut\{Column, Document, Page, PageNumber, Row, Spacer, Table, Text};

use function Papercut\{mm, pt};

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!class_exists(Document::class) && is_file($autoload)) {
    require_once $autoload;
}

$courses = array_map(
    static fn (int $i): array => ["Course $i", (string) ($i % 4 + 1), ['A', 'B', 'C', 'B+', 'A-'][$i % 5]],
    range(1, 300),
);

return Document::make(
    Page::a4()->margin(mm(10))
        ->header(Row::make(Text::make('Official Transcript')->bold()->size(pt(16)), Spacer::flex(), Text::make('One Learning Community')))
        ->body(Column::make(
            Text::h2('Student: Ada Lovelace'),
            Table::make()->columns('3fr 25mm 20mm')->head('Course', 'Credits', 'Grade')
                ->rows($courses, static fn (array $c): array => [$c[0], $c[1], Text::make($c[2])->right()]),
        )->gap(pt(8)))
        ->footer(PageNumber::make('Page {page} of {total}')->center()),
)->title('Transcript')->createdAt(new DateTimeImmutable('2026-01-01T00:00:00Z'));
