<?php

declare(strict_types=1);

use Papercut\Markup\Markup;
use Papercut\{Column, Document, Page};

use function Papercut\{mm, pt};

// Generic, made-up content: a long statistics table (two header rows, one with colspan, a footnote row) that
// splits across pages and repeats its header, a rule, and a rubric grid with a rowspan and cell lists.
$labels = [
    'Primary assessment completed within the first term', 'Follow-up sessions attended by enrolled participants',
    'Materials returned in good condition after the course', 'Written feedback received from supervisors',
    'Practical workshops delivered on schedule', 'Participants reporting a clear understanding of goals',
    'Late submissions as a share of all submissions', 'Resources borrowed from the shared library',
];
$rows = '';
foreach (range(1, 40) as $i) {
    $label = $labels[$i % count($labels)] . " (group $i)";
    $rows .= sprintf(
        '<tr><td>%s</td><td>%d</td><td>%d</td><td>%d.%d</td><td>%d</td></tr>',
        $label, 10 + $i * 3, 5 + $i, $i % 9, $i % 7, 100 + $i * 11,
    );
}

$stats = <<<HTML
<table>
<caption>Table 1. Participation by category</caption>
<tr><th>Category</th><th colspan="4">Results by period</th></tr>
<tr><th>Measure</th><th>Count</th><th>Rate</th><th>Mean</th><th>Total</th></tr>
$rows
<tr><td colspan="5">Note: all figures are invented for illustration.</td></tr>
</table>
HTML;

$cell = static fn (string $t): string => "<p>$t</p><ul><li>Evidence of $t</li><li>Clear examples</li></ul>";
$rubric = <<<HTML
<table>
<caption>Table 2. Scoring rubric</caption>
<tr><th>Criterion</th><th>Beginning</th><th>Developing</th><th>Secure</th></tr>
<tr><th>Structure</th><td>{$cell('a loose order')}</td><td rowspan="2">{$cell('a mostly consistent order that carries across both of these criteria')}<p>The reader can follow the argument from start to end without help, and each section links to the next.</p></td><td>{$cell('a firm order')}</td></tr>
<tr><th>Clarity</th><td>{$cell('unclear phrasing')}</td><td>{$cell('precise phrasing')}</td></tr>
<tr><th>Sources</th><td>{$cell('few sources')}</td><td>{$cell('some sources')}</td><td>{$cell('varied sources')}</td></tr>
</table>
HTML;

// A spanning cell taller than a page: its group has to split, so the grid is checked across the break.
$items = implode('', array_map(static fn (int $n): string => "<li>Checklist item $n for the long guidance cell</li>", range(1, 45)));
$long = <<<HTML
<table>
<caption>Table 3. Guidance by stage</caption>
<tr><th>Stage</th><th>Guidance</th><th>Owner</th></tr>
<tr><th>Plan</th><td>Agree the scope</td><td>Lead</td></tr>
<tr><th>Prepare</th><td rowspan="2"><ul>$items</ul></td><td>Lead</td></tr>
<tr><th>Review</th><td>Peer</td></tr>
<tr><th>Close</th><td>Short note</td><td>Lead</td></tr>
</table>
HTML;

return Document::make(
    Page::a5()->margin(mm(12))->body(
        Column::make(
            Markup::html($stats),
            Markup::html('<hr>'),
            Markup::html($rubric),
            Markup::html($long),
        )->gap(pt(8)),
    ),
)->createdAt(new DateTimeImmutable('2026-01-01T00:00:00Z'));
