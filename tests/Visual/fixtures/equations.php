<?php

declare(strict_types=1);

use Papercut\Math\Math;
use Papercut\Style\TextAlign;
use Papercut\{Column, Document, Equation, Page, Span, Table, Text};

use function Papercut\{mm, pt};

// The formulas are the ones in packages/math/tests/Fixtures.php. Generic, made-up formulas: fractions, roots, scripts, fences and text, then alignment, size and colour from context.
$quad = Math::row('x =', Math::frac(Math::row('−b ±', Math::sqrt(Math::sup('b', '2'), '− 4ac')), '2a'));

$formulas = [
    'Quadratic' => $quad,
    'Slope' => Math::row('m =', Math::frac(
        Math::row(Math::sub('y', '2'), '−', Math::sub('y', '1')),
        Math::row(Math::sub('x', '2'), '−', Math::sub('x', '1')),
    )),
    'Cube root' => Math::row(Math::root('27', '3'), '= 3'),
    'Mixed number' => Math::row('3', Math::frac('1', '2')),
    'Fraction' => Math::row(Math::frac('a', 'b'), '= c'),
    'Words' => Math::row(Math::text('density'), '=', Math::frac(Math::text('mass'), Math::text('volume'))),
    'Squared fraction' => Math::sup(Math::fenced('(', ')', Math::frac('a', 'b')), '2'),
    'Growth' => Math::sup(Math::fenced('(', ')', Math::row('1 +', Math::frac('1', 'n'))), 'n'),
    'Both scripts' => Math::subSup('x', '1', '2'),
    'Decay' => Math::sup('e', '−kt'),
    'Script digits' => Math::row('b² + x₂ = c'),
    'Nested radical' => Math::sqrt('1 +', Math::frac('1', Math::row('1 +', Math::frac('1', 'x')))),
    'Tall fence' => Math::row(Math::fenced('[', ']', Math::frac(Math::frac('1', 'x'), Math::frac('1', Math::frac('1', 'y')))), '= z'),
];

$items = [Text::make('Equations')];
foreach ($formulas as $label => $formula) {
    $items[] = Text::make($label)->size(pt(8))->color('#64748b');
    $items[] = Equation::of($formula);
}

$items = [
    ...$items,
    Text::make('Centered')->size(pt(8))->color('#64748b'),
    Equation::of($quad)->textAlign(TextAlign::Center),
    Text::make('Right')->size(pt(8))->color('#64748b'),
    Equation::of($quad)->textAlign(TextAlign::Right),
    Text::make('Large and coloured')->size(pt(8))->color('#64748b'),
    Equation::of($quad)->size(pt(20))->color('#b91c1c'),
    Text::make('Inline')->size(pt(8))->color('#64748b'),
    Text::spans(
        Span::text('The roots are '),
        Span::math($quad),
        Span::text(', where b² − 4ac is the discriminant, and the area is '),
        Span::math(Math::row('A =', Math::sup('πr', '2'))),
        Span::text('.'),
    ),
    Table::make()->columns('auto auto')->row(
        Text::spans(Span::text('Area '), Span::math(Math::row('A =', Math::sup('πr', '2')))),
        Text::spans(Span::text('Slope '), Span::math(Math::row('m =', Math::frac(Math::row('Δy'), Math::row('Δx'))))),
    ),
];

return Document::make(
    Page::a4()->margin(mm(15))->body(
        Column::make(...$items)->gap(pt(6)),
    ),
)->createdAt(new DateTimeImmutable('2026-01-01T00:00:00Z'));
