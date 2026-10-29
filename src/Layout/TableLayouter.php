<?php

declare(strict_types=1);

namespace Papercut\Layout;

use Papercut\{Node, Table, TableCell, TableRow, View};
use Papercut\Exception\UnknownAttribute;
use Papercut\Style\{ComputedStyle, Justify, Style, Theme};
use Papercut\Value\{Auto, Fr, Length, Percent};

/**
 * Tables: a grid of columns sized from the track list, rows stacked top to
 * bottom, every cell stretched to its row's height. Cells (TableCell) are
 * block containers laid out like a Column; each row is a box of the table's
 * width painted with the row's own background, border and padding.
 *
 * Cells sit on the grid TableGrid resolves (colspan and rowspan). Rows tied
 * together by rowspans form a group, laid out as one unit: a cell spanning
 * rows covers them, its shortfall spread evenly over them, and is painted
 * after their row boxes. A row no span crosses is a group of one.
 *
 * Fragmentation is by group. Header rows open the first fragment and, when the
 * table repeats its header, every continuation. A group that does not fit
 * after other rows moves to the next fragment: BreakToken(index: k), k its
 * first body row. A lone body row too tall for a fresh page splits its cells
 * in parallel: BreakToken(index: k, parallel: [cell index => ?token]), null
 * = finished; on resume finished cells draw nothing.
 *
 * A group of several rows too tall for a fresh page places the rows that fit
 * (or splits its first row in parallel, spanning cells included), and
 * spanning cells that run on keep their tokens:
 * BreakToken(index: k, child: BreakToken(index: s, parallel: [o => ?token])),
 * k the body row to continue at, s the group's first all-row index, o a
 * cell's ordinal in the group's placements (row by row). An entry exists for
 * every started cell still covering row k (null = finished, drawing nothing
 * more); a cell without one starts afresh. Entries for cells of row k itself
 * mean that row resumes mid-split.
 *
 * Every cell slot paints its whole slice: a cell that breaks fills the slice
 * (Constraints::$fill), one that ends is stretched, and one that finished on
 * an earlier slice while its rows go on is a decoration-only box. A cell draws
 * its top edge only on the slice that starts it and its bottom edge only on the
 * slice that ends its rows (Constraints::$open otherwise).
 *
 * A resumed group on a page that is not fresh splits anyway, like a resumed row,
 * rather than moving whole to the next page.
 *
 * Column measures and resolved grids are cached per Table instance (keyed by
 * the inherited style and width), so continuations and retries neither
 * re-measure cells nor repeat column warnings.
 */
final class TableLayouter implements Layouter
{
    private const float EPSILON = 1e-9;

    /** @var \WeakMap<Table, array<string, list<array{min: float, max: float}>>> */
    private \WeakMap $content;
    /** @var \WeakMap<Table, array<string, array{width: float, widths: list<float>, offsets: list<float>}>> */
    private \WeakMap $grids;
    /** @var \WeakMap<Table, true> tables already warned about a dropped repeated header */
    private \WeakMap $headerWarned;
    /** @var \WeakMap<Table, true> tables already warned about clamped spans */
    private \WeakMap $clampWarned;
    /** @var \WeakMap<TableCell, TableCell> cells with the theme's defaults applied; stable identities keep style memos hot */
    private \WeakMap $prepared;
    /** @var \WeakMap<TableCell, View> the block container each cell is laid out as */
    private \WeakMap $columns;
    /** @var \WeakMap<TableCell, true> prepared cells, which belong to a table */
    private \WeakMap $inTable;
    /** @var \WeakMap<TableCell, true> bare cells already warned about */
    private \WeakMap $strayWarned;

    public function __construct(private readonly BlockLayouter $block)
    {
        $this->content = new \WeakMap();
        $this->grids = new \WeakMap();
        $this->headerWarned = new \WeakMap();
        $this->clampWarned = new \WeakMap();
        $this->prepared = new \WeakMap();
        $this->columns = new \WeakMap();
        $this->inTable = new \WeakMap();
        $this->strayWarned = new \WeakMap();
    }

    public function layout(Node $node, ComputedStyle $style, Constraints $c, ?BreakToken $token, LayoutEngine $engine): LayoutResult
    {
        if ($node instanceof TableCell) {
            $this->warnStray($node, $engine);
            return $this->block->layout($this->column($node), $style, $c, $token, $engine);
        }
        $table = self::table($node);
        $first = $token === null;
        $borderWidth = BoxGeometry::borderBoxWidth($style, $c->width);
        $contentWidth = BoxGeometry::contentWidth($style, $c->width);
        $grid = $this->grid($table, $style, $contentWidth, $engine);

        $available = $c->height === null
            ? null
            : $c->height - ($first ? BoxGeometry::topEdge($style) : 0.0) - BoxGeometry::bottomEdge($style);

        $body = $table->bodyRows();
        $count = count($body);
        $headers = $table->headerRows();
        $offset = count($headers);
        $start = min(max(0, $token->index ?? 0), $count);
        $tableGrid = TableGrid::of($table);
        if (!isset($this->clampWarned[$table])) {
            $this->clampWarned[$table] = true;
            if ($tableGrid->clampedRowspans !== []) {
                $engine->context()->warn('Table cell rowspan runs past the last row; clamped');
            }
            if ($tableGrid->clampedColspans !== []) {
                $engine->context()->warn('Table cell colspan runs into a rowspan from above; clamped');
            }
        }

        /** @var list<array{height: float, fragment: Fragment}> $headerRows */
        $headerRows = [];
        if ($first || $table->repeatsHeader()) {
            for ($r = 0; $r < $offset; $r = $end + 1) {
                $end = $tableGrid->groupEnd($r);
                $headerRows[] = $this->measureUnit($table, $r, $end, $grid, $style, $c->fresh && $headerRows === [], $engine);
            }
        }
        $headerHeight = array_sum(array_column($headerRows, 'height'));

        if (!$first && $headerRows !== [] && $c->fresh && $available !== null && $start < $count
            && $this->crowdsOutRow($table, $offset + $start, $token->parallel !== [] || $token->child !== null, $headerHeight, $available, $grid, $style, $engine)) {
            $headerRows = [];
            $headerHeight = 0.0;
            if (!isset($this->headerWarned[$table])) {
                $this->headerWarned[$table] = true;
                $engine->context()->warn('Table header leaves no room for a row; not repeated on that page');
            }
        }
        if ($headerRows !== [] && !$c->fresh && $available !== null && $headerHeight > $available + self::EPSILON) {
            return LayoutResult::doesNotFit();
        }

        /** @var list<array{y: float, fragment: Fragment}> $placed */
        $placed = [];
        $cursor = 0.0;
        foreach ($headerRows as $measured) {
            $placed[] = ['y' => $cursor, 'fragment' => $measured['fragment']];
            $cursor += $measured['height'];
        }

        $breakAt = null;
        $rowsOnPage = 0;
        for ($k = $start; $k < $count; $k++) {
            $resuming = $k === $start && $token !== null && $token->parallel !== [];
            $group = $k === $start ? $token?->child : null;
            if ($group === null && !$resuming) {
                $end = $tableGrid->groupEnd($offset + $k) - $offset;
                $measured = $this->measureUnit($table, $offset + $k, $offset + $end, $grid, $style, $c->fresh && $placed === [], $engine);
                if ($available === null || $cursor + $measured['height'] <= $available + self::EPSILON) {
                    $placed[] = ['y' => $cursor, 'fragment' => $measured['fragment']];
                    $cursor += $measured['height'];
                    $rowsOnPage++;
                    $k = $end;
                    continue;
                }
                if ($rowsOnPage > 0) {
                    $breakAt = new BreakToken(index: $k);
                    break;
                }
                if (!$c->fresh) {
                    return LayoutResult::doesNotFit();
                }
                if ($end > $k) {
                    $group = new BreakToken(index: $offset + $k);
                }
            }

            if ($group !== null) {
                // A group taller than the room left (fresh page) or one resumed from a split: as much as fits.
                $room = $available === null ? null : max(0.0, $available - $cursor);
                $slice = $this->layoutGroup($table, $group->index, $offset + $k, $group->parallel, $grid, $style, $room, $c->fresh, $engine);
                if ($slice === null) {
                    return LayoutResult::doesNotFit();
                }
                $placed[] = ['y' => $cursor, 'fragment' => $slice['fragment']];
                $cursor += $slice['height'];
                $rowsOnPage++;
                if ($slice['token'] !== null) {
                    $breakAt = new BreakToken(index: $slice['next'] - $offset, child: $slice['token']);
                    break;
                }
                $k = $tableGrid->groupEnd($group->index) - $offset;
                continue;
            }

            $room = $available === null ? null : max(0.0, $available - $cursor);
            $split = $this->splitRow($table, $body[$k], $offset + $k, $grid, $style, $room, $c->fresh, $resuming ? $token->parallel : null, $engine);
            if ($split === null) {
                return LayoutResult::doesNotFit();
            }
            $placed[] = ['y' => $cursor, 'fragment' => $split['fragment']];
            $cursor += $split['height'];
            $rowsOnPage++;
            if (ParallelSplit::pending($split['tokens'])) {
                $breakAt = new BreakToken(index: $k, parallel: $split['tokens']);
                break;
            }
        }

        if ($breakAt !== null && $style->keepTogether && !$c->fresh) {
            return LayoutResult::doesNotFit();
        }

        $last = $breakAt === null;
        $f = new Fragment($borderWidth, BoxGeometry::outerHeight($style, $cursor, $first, $last));
        BoxGeometry::decorate($f, $style, $first, $last);
        $ox = $style->borderWidth->left->points + $style->padding->left->points;
        $oy = $first ? BoxGeometry::topEdge($style) : 0.0;
        foreach ($placed as $p) {
            $f->addChild($ox, $oy + $p['y'], $p['fragment']);
        }

        return $breakAt === null ? LayoutResult::complete($f) : LayoutResult::partial($f, $breakAt);
    }

    public function intrinsic(Node $node, ComputedStyle $style, LayoutEngine $engine): array
    {
        if ($node instanceof TableCell) {
            $this->warnStray($node, $engine);
            return $this->block->intrinsic($this->column($node), $style, $engine);
        }
        if ($style->width instanceof Length) {
            return ['min' => $style->width->points, 'max' => $style->width->points];
        }
        $table = self::table($node);
        $columns = $this->columnContent($table, $style, $engine);
        $tracks = self::trackList($table);
        $min = 0.0;
        $max = 0.0;
        foreach ($columns as $k => $size) {
            $fixed = $tracks[$k] instanceof Length ? $tracks[$k]->points : null;
            $min += $fixed ?? $size['min'];
            $max += $fixed ?? $size['max'];
        }
        $edges = $style->padding->horizontal() + $style->borderWidth->horizontal() + $this->rowEdge($table, $style, $engine);
        return ['min' => $min + $edges, 'max' => $max + $edges];
    }

    /**
     * True when a repeated header of $headerHeight leaves no room for the next
     * body group (from all-row index $r): nothing at all is left, or the group
     * would fit the page without it.
     *
     * @param array{width: float, widths: list<float>, offsets: list<float>} $grid
     */
    private function crowdsOutRow(Table $table, int $r, bool $resuming, float $headerHeight, float $available, array $grid, ComputedStyle $style, LayoutEngine $engine): bool
    {
        $room = $available - $headerHeight;
        if ($room <= self::EPSILON) {
            return true;
        }
        if ($resuming) {
            return false;
        }
        $height = $this->measureUnit($table, $r, TableGrid::of($table)->groupEnd($r), $grid, $style, false, $engine)['height'];
        return $height > $room + self::EPSILON && $height <= $available + self::EPSILON;
    }

    /**
     * Column widths in pt for a table whose border box is offered $available,
     * without pagination.
     *
     * @return list<float>
     */
    public function columns(Table $table, ComputedStyle $style, float $available, LayoutEngine $engine): array
    {
        return $this->grid($table, $style, BoxGeometry::contentWidth($style, $available), $engine)['widths'];
    }

    /**
     * Column widths and x offsets for content width $width, computed once per
     * table, style and width; column warnings are emitted only then.
     *
     * @return array{width: float, widths: list<float>, offsets: list<float>}
     */
    private function grid(Table $table, ComputedStyle $style, float $width, LayoutEngine $engine): array
    {
        $key = self::styleKey($style) . '|' . $width;
        $cached = $this->grids[$table] ?? [];
        if (isset($cached[$key])) {
            return $cached[$key];
        }

        // Columns fit inside every row box: size them against the width minus the widest row side edges.
        $edge = $this->rowEdge($table, $style, $engine);
        $width = max(0.0, $width - $edge);
        $content = $this->columnContent($table, $style, $engine);
        $n = count($content);
        $given = $table->trackList()?->count();
        if ($given !== null && $given !== $n) {
            $engine->context()->warnOnce(sprintf('Table has %d columns but %d tracks', $n, $given));
        }
        $tracks = self::trackList($table);

        $fitted = $table->fitsColumns() ? $this->fittedLengths($table, $style, $tracks, $content, $width, $engine) : [];
        $widths = array_fill(0, $n, 0.0);
        $auto = [];
        $fr = [];
        foreach ($tracks as $k => $track) {
            if ($track instanceof Length) {
                $widths[$k] = $fitted[$k] ?? $track->points;
            } elseif ($track instanceof Percent) {
                $widths[$k] = $track->resolve($width);
            } elseif ($track instanceof Auto) {
                $auto[] = $k;
            } else {
                $fr[$k] = $track->value;
            }
        }

        // Auto tracks: max-content if it fits, min-content if even that overflows, else in between ∝ (max - min).
        $remaining = $width - array_sum($widths);
        $sumMin = 0.0;
        $sumMax = 0.0;
        foreach ($auto as $k) {
            $sumMin += $content[$k]['min'];
            $sumMax += $content[$k]['max'];
        }
        foreach ($auto as $k) {
            $min = $content[$k]['min'];
            $max = $content[$k]['max'];
            $widths[$k] = match (true) {
                $sumMax <= $remaining + self::EPSILON => $max,
                $sumMin >= $remaining - self::EPSILON => $min,
                default => $min + ($remaining - $sumMin) * ($max - $min) / ($sumMax - $sumMin),
            };
        }

        // Fr tracks share what is left, never below their column's min-content: a clamped
        // track is frozen at its min-content and the rest is re-shared (CSS grid).
        $hasFr = $fr !== [];
        $remaining = $width - array_sum($widths);
        while ($fr !== []) {
            $totalFr = array_sum($fr);
            $clamped = [];
            foreach ($fr as $k => $value) {
                $share = $remaining > self::EPSILON && $totalFr > 0.0 ? $remaining * $value / $totalFr : 0.0;
                if ($content[$k]['min'] > $share + self::EPSILON) {
                    $clamped[$k] = $content[$k]['min'];
                }
                $widths[$k] = max($content[$k]['min'], $share);
            }
            if ($clamped === []) {
                break;
            }
            foreach ($clamped as $k => $min) {
                $widths[$k] = $min;
                $remaining -= $min;
                unset($fr[$k]);
            }
        }

        $total = array_sum($widths);
        if ($table->fillsWidth() && !$hasFr && $auto !== [] && $width - $total > self::EPSILON) {
            $leftover = $width - $total;
            $maxSum = 0.0;
            foreach ($auto as $k) {
                $maxSum += $content[$k]['max'];
            }
            foreach ($auto as $k) {
                $widths[$k] += $maxSum > self::EPSILON ? $leftover * $content[$k]['max'] / $maxSum : $leftover / count($auto);
            }
            $total = array_sum($widths);
        }
        if ($total > $width + self::EPSILON) {
            $engine->context()->warnOnce(sprintf('Table columns need %.2fpt but only %.2fpt is available', $total, $width));
        }

        $offsets = [];
        $x = 0.0;
        foreach ($widths as $w) {
            $offsets[] = $x;
            $x += $w;
        }
        $grid = ['width' => max($width, $total) + $edge, 'widths' => array_values($widths), 'offsets' => $offsets];
        $cached[$key] = $grid;
        $this->grids[$table] = $cached;
        return $grid;
    }

    /**
     * For a table that fits its columns: the Length tracks scaled down together so they and the minimum
     * widths of the other columns fit $width, none below its own min-content when that can be honoured.
     * Empty when everything fits already, or when even the minimums do not.
     *
     * @param list<Length|Percent|Fr|Auto> $tracks
     * @param list<array{min: float, max: float}> $content
     * @return array<int, float> column => width
     */
    private function fittedLengths(Table $table, ComputedStyle $style, array $tracks, array $content, float $width, LayoutEngine $engine): array
    {
        $lengths = [];
        $other = 0.0;
        foreach ($tracks as $k => $track) {
            if ($track instanceof Length) {
                $lengths[$k] = $track->points;
            } elseif ($track instanceof Percent) {
                $other += $track->resolve($width);
            } else {
                $other += $content[$k]['min'];
            }
        }
        $room = $width - $other;
        if ($lengths === [] || array_sum($lengths) <= $room + self::EPSILON || $room <= self::EPSILON) {
            return [];
        }

        $floor = [];
        foreach ($this->columnContent($table, $style, $engine, true) as $k => $c) {
            $floor[$k] = isset($lengths[$k]) ? min($c['min'], $lengths[$k]) : 0.0;
        }
        if (array_sum(array_intersect_key($floor, $lengths)) > $room + self::EPSILON) {
            return [];
        }

        // freeze the columns that would fall below their min-content, then share what is left among the rest
        $frozen = [];
        do {
            $stable = true;
            $open = array_diff_key($lengths, $frozen);
            $scale = ($room - array_sum($frozen)) / array_sum($open);
            foreach ($open as $k => $points) {
                if ($points * $scale < $floor[$k] - self::EPSILON) {
                    $frozen[$k] = $floor[$k];
                    $stable = false;
                }
            }
        } while (!$stable);

        $out = $frozen;
        foreach (array_diff_key($lengths, $frozen) as $k => $points) {
            $out[$k] = $points * $scale;
        }
        return $out;
    }

    /** Largest left + right padding and border over all rows. */
    private function rowEdge(Table $table, ComputedStyle $style, LayoutEngine $engine): float
    {
        $edge = 0.0;
        foreach (self::allRows($table) as $r => $row) {
            $rs = $engine->styleOf($row, $style, $r);
            $edge = max($edge, $rs->padding->horizontal() + $rs->borderWidth->horizontal());
        }
        return $edge;
    }

    /**
     * Per-column min/max-content: the max over its span-1 cells, then each
     * spanning cell's shortfall spread evenly over the auto columns it covers.
     * A rowspan cell counts like a single-row cell of its colspan.
     * Span-1 cells in fixed (Length) tracks are not measured: nothing reads
     * their column's content size, unless $measureFixed. Cached per table and style.
     *
     * @return list<array{min: float, max: float}>
     */
    private function columnContent(Table $table, ComputedStyle $style, LayoutEngine $engine, bool $measureFixed = false): array
    {
        $key = self::styleKey($style) . ($measureFixed ? '|fixed' : '');
        $cached = $this->content[$table] ?? [];
        if (isset($cached[$key])) {
            return $cached[$key];
        }

        $rows = self::allRows($table);
        $placements = TableGrid::of($table)->placements;
        $tracks = self::trackList($table);
        $n = count($tracks);
        $columns = array_fill(0, $n, ['min' => 0.0, 'max' => 0.0]);
        /** @var list<array{col: int, span: int, min: float, max: float}> $spanning */
        $spanning = [];
        foreach ($rows as $r => $row) {
            $this->inRow($row, $r, $style, $engine, function (ComputedStyle $rowStyle) use ($placements, $r, $engine, $tracks, $measureFixed, &$columns, &$spanning): void {
                foreach ($placements[$r] as $p) {
                    $col = $p->col;
                    if ($p->colspan === 1 && !$measureFixed && $tracks[$col] instanceof Length) {
                        continue;
                    }
                    $size = $engine->intrinsic($this->prepare($p->cell, $engine->context()->theme), $rowStyle, $p->index);
                    if ($p->colspan === 1) {
                        $columns[$col] = [
                            'min' => max($columns[$col]['min'], $size['min']),
                            'max' => max($columns[$col]['max'], $size['max']),
                        ];
                    } else {
                        $spanning[] = ['col' => $col, 'span' => $p->colspan, 'min' => $size['min'], 'max' => $size['max']];
                    }
                }
            });
        }

        foreach ($spanning as $s) {
            $covered = range($s['col'], $s['col'] + $s['span'] - 1);
            $autos = array_values(array_filter($covered, static fn (int $k): bool => $tracks[$k] instanceof Auto));
            if ($autos === []) {
                continue;
            }
            $sumMin = 0.0;
            $sumMax = 0.0;
            foreach ($covered as $k) {
                $fixed = $tracks[$k] instanceof Length ? $tracks[$k]->points : null;
                $sumMin += $fixed ?? $columns[$k]['min'];
                $sumMax += $fixed ?? $columns[$k]['max'];
            }
            $addMin = max(0.0, $s['min'] - $sumMin) / count($autos);
            $addMax = max(0.0, $s['max'] - $sumMax) / count($autos);
            foreach ($autos as $k) {
                $columns[$k] = ['min' => $columns[$k]['min'] + $addMin, 'max' => $columns[$k]['max'] + $addMax];
            }
        }

        $cached[$key] = $columns;
        $this->content[$table] = $cached;
        return $columns;
    }

    /**
     * Measures rows $first..$last (all-row indexes, one rowspan group): a lone
     * row through measureRow(), several through measureGroup().
     *
     * @param array{width: float, widths: list<float>, offsets: list<float>} $grid
     * @return array{height: float, fragment: Fragment}
     */
    private function measureUnit(Table $table, int $first, int $last, array $grid, ComputedStyle $style, bool $fresh, LayoutEngine $engine): array
    {
        return $first === $last
            ? $this->measureRow($table, self::rowAt($table, $first), $first, $grid, $style, $fresh, $engine)
            : $this->measureGroup($table, $first, $last, $grid, $style, $fresh, $engine);
    }

    /**
     * Lays a row out at unbounded height, stretching every cell to the row's
     * content height, inside a decorated row box. A shorter cell is laid out
     * again at the row height only when that changes what it paints
     * (decoration, vertical alignment, growing children); otherwise its
     * fragment is boxed at the row height.
     *
     * @param array{width: float, widths: list<float>, offsets: list<float>} $grid
     * @return array{height: float, fragment: Fragment}
     */
    private function measureRow(Table $table, TableRow $row, int $r, array $grid, ComputedStyle $style, bool $fresh, LayoutEngine $engine): array
    {
        return $this->inRow($row, $r, $style, $engine, function (ComputedStyle $rowStyle) use ($table, $row, $r, $grid, $fresh, $engine): array {
            $slots = self::slots($table, $r, $grid);
            $cellsOf = $row->cells();
            ['height' => $height, 'fragments' => $frags] = $this->measureCells($cellsOf, array_keys($cellsOf), $slots, $rowStyle, $fresh, $engine);
            $cells = [];
            foreach ($cellsOf as $j => $cell) {
                $cells[] = ['x' => $slots[$j]['x'], 'fragment' => $this->stretch($cell, $j, $frags[$j], $slots[$j]['width'], $height, $rowStyle, $fresh, $engine)];
            }
            $fragment = self::rowFragment($rowStyle, $grid['width'], $height, $cells, true, true);
            return ['height' => $fragment->height, 'fragment' => $fragment];
        });
    }

    /**
     * Lays out a whole rowspan group, rows $first..$last (all-row indexes), at
     * unbounded height.
     *
     * @param array{width: float, widths: list<float>, offsets: list<float>} $grid
     * @return array{height: float, fragment: Fragment}
     */
    private function measureGroup(Table $table, int $first, int $last, array $grid, ComputedStyle $style, bool $fresh, LayoutEngine $engine): array
    {
        $whole = $this->layoutGroup($table, $first, $first, [], $grid, $style, null, $fresh, $engine);
        assert($whole !== null && $whole['next'] === $last + 1);
        return ['height' => $whole['height'], 'fragment' => $whole['fragment']];
    }

    /**
     * Lays out the rowspan group starting at all-row index $start, from row
     * $from on, in $room (null = unbounded).
     *
     * Each row's height comes from its single-row cells; a spanning cell that
     * needs more than its rows give (the row edges between them included)
     * spreads the shortfall evenly over them. Row boxes come first, then the
     * spanning cells, so later rows never paint over them.
     *
     * When the rows left do not fit, rows go in while they fit, each with
     * the shortfall of the spanning cells that end on it only (a cell that
     * breaks anyway does not push rows off the page). A row that fits only
     * without that shortfall goes in last, those cells breaking beside it and
     * the row resuming on the next page. The last row placed takes the rest
     * of the page when a spanning cell runs on beside it; such a cell is laid
     * out in the height of its rows on this page, keeping its break token.
     * A spanning cell ending on that tail row must place something there; if it
     * cannot, the tail row is dropped and the cell starts again with it. A cell
     * that cannot place anything on a fresh page is DoesNotFit, as a single-row cell is.
     * When not even the first row fits, that row splits in parallel, with
     * every spanning cell covering it taking part.
     *
     * $started holds the token of every cell already started, by its ordinal
     * in the group's placements (row by row): null = finished, drawing nothing
     * more. A cell of row $from in it means that row resumes mid-split.
     *
     * @param array<int, ?BreakToken> $started
     * @param array{width: float, widths: list<float>, offsets: list<float>} $grid
     * @return ?array{height: float, fragment: Fragment, next: int, token: ?BreakToken} null when a cell cannot place anything;
     *     next is the all-row index to continue at; token (null once the group is done) continues the group there
     */
    private function layoutGroup(Table $table, int $start, int $from, array $started, array $grid, ComputedStyle $style, ?float $room, bool $fresh, LayoutEngine $engine): ?array
    {
        $tableGrid = TableGrid::of($table);
        $last = $tableGrid->groupEnd($start);

        /** @var array<int, GridCell> $cells by ordinal */
        $cells = [];
        $slots = [];
        for ($r = $start; $r <= $last; $r++) {
            $slots[$r] = self::slots($table, $r, $grid);
            foreach ($tableGrid->placements[$r] as $p) {
                $cells[] = $p;
            }
        }
        $resumed = false;
        foreach ($cells as $o => $p) {
            $resumed = $resumed || ($p->row === $from && array_key_exists($o, $started));
        }

        /** @var array<int, ComputedStyle> $rowStyles */
        $rowStyles = [];
        $rowStyle = function (int $r) use (&$rowStyles, $table, $style, $engine): ComputedStyle {
            return $rowStyles[$r] ??= $engine->styleOf(self::rowAt($table, $r), $style, $r);
        };
        // Lays cell $o out at its slot width, inside its starting row. In a height it takes the whole of it
        // when it breaks (and when it ends, if $stretch); $open: its rows go on in the next slice.
        $lay = function (int $o, ?float $height, bool $cellFresh, bool $open = false, bool $stretch = true) use ($cells, $slots, $started, $table, $style, $engine): LayoutResult {
            $p = $cells[$o];
            $w = $slots[$p->row][$p->index]['width'];
            $c = new Constraints($w, $height, $cellFresh, $stretch ? $height : null, $height, $open);
            return $this->inRow(self::rowAt($table, $p->row), $p->row, $style, $engine, fn (ComputedStyle $rs): LayoutResult => $this->layoutCell($p->cell, $p->index, $w, $rs, $c, $started[$o] ?? null, $engine));
        };
        // Stretches cell $o's fragment, laid out from its token, to $height.
        $stretchCell = function (int $o, Fragment $f, float $height, bool $cellFresh, bool $open) use ($cells, $slots, $started, $table, $style, $engine): Fragment {
            $p = $cells[$o];
            $w = $slots[$p->row][$p->index]['width'];
            return $this->inRow(self::rowAt($table, $p->row), $p->row, $style, $engine, fn (ComputedStyle $rs): Fragment => $this->stretch($p->cell, $p->index, $f, $w, $height, $rs, $cellFresh, $engine, $started[$o] ?? null, $open));
        };
        // A cell finished on an earlier slice whose rows go on: only its box, $height tall.
        $decorate = function (int $o, float $height, bool $closed) use ($cells, $slots, $table, $style, $engine): Fragment {
            $p = $cells[$o];
            $f = new Fragment($slots[$p->row][$p->index]['width'], $height);
            $this->inRow(self::rowAt($table, $p->row), $p->row, $style, $engine, function (ComputedStyle $rs) use ($p, $f, $closed, $engine): void {
                BoxGeometry::decorate($f, $engine->styleOf($this->prepare($p->cell, $engine->context()->theme), $rs, $p->index), false, $closed);
            });
            return $f;
        };

        // Cells with content left: single-row cells of rows $from.., spanning cells covering $from...
        /** @var array<int, list<int>> $single ordinals by row */
        $single = [];
        /** @var array<int, int> $spanning ordinal => first row on this page */
        $spanning = [];
        for ($r = $from; $r <= $last; $r++) {
            $single[$r] = [];
            $rowStyle($r);
        }
        foreach ($cells as $o => $p) {
            $end = $p->row + $p->rowspan - 1;
            if ($end < $from || (array_key_exists($o, $started) && $started[$o] === null)) {
                continue;
            }
            if ($p->rowspan > 1) {
                $spanning[$o] = max($p->row, $from);
            } else {
                $single[$p->row][] = $o;
            }
        }
        $topOf = static fn (int $r): bool => !($r === $from && $resumed); // whether row $r starts here, with its top edge

        // Unbounded measure: row heights from single-row cells.
        /** @var array<int, float> $natural row content heights */
        $natural = [];
        $frags = [];
        for ($r = $from; $r <= $last; $r++) {
            $natural[$r] = 0.0;
            foreach ($single[$r] as $o) {
                $frags[$o] = $lay($o, null, $fresh && $r === $from)->fragment ?? new Fragment($slots[$r][$cells[$o]->index]['width'], 0.0);
                $natural[$r] = max($natural[$r], $frags[$o]->height);
            }
        }
        foreach ($spanning as $o => $first) {
            $frags[$o] = $lay($o, null, $fresh && $first === $from)->fragment ?? new Fragment($slots[$cells[$o]->row][$cells[$o]->index]['width'], 0.0);
        }
        $endOf = static fn (int $o): int => $cells[$o]->row + $cells[$o]->rowspan - 1;
        // Spreads the shortfall of spanning cells $os evenly over the rows each covers here.
        $spread = function (array $heights, array $os) use ($spanning, $frags, $endOf, &$rowStyles): array {
            foreach ($os as $o) {
                $first = $spanning[$o];
                $end = $endOf($o);
                $shortfall = $frags[$o]->height - self::spanHeight($first, $end, $rowStyles, $heights, false);
                if ($shortfall > self::EPSILON) {
                    for ($r = $first; $r <= $end; $r++) {
                        $heights[$r] += $shortfall / ($end - $first + 1);
                    }
                }
            }
            return $heights;
        };
        $outer = fn (array $heights, int $to): float => array_sum(array_map(
            static fn (int $r): float => BoxGeometry::outerHeight($rowStyles[$r], $heights[$r], $topOf($r), true),
            $to >= $from ? range($from, $to) : [],
        ));

        // The whole rest of the group, every spanning cell's shortfall spread over its rows.
        $heights = $spread($natural, array_keys($spanning));
        $fit = $last;
        $tail = false; // the last row placed holds spanning cells that end on it but break
        if ($room !== null && $outer($heights, $last) > $room + self::EPSILON) {
            // Not all of it fits: rows go in while they fit, each with the shortfall of the
            // spanning cells ending on it. A row that fits only without that shortfall goes
            // in as the last one, those cells breaking beside it.
            $cap = $last;
            do {
                $retry = false;
                $heights = $natural;
                $fit = $from - 1;
                $tail = false;
                for ($r = $from; $r <= $cap; $r++) {
                    $try = $spread($heights, array_keys(array_filter($spanning, static fn (int $first, int $o): bool => $endOf($o) === $r, ARRAY_FILTER_USE_BOTH)));
                    if ($outer($try, $r) <= $room + self::EPSILON) {
                        $heights = $try;
                        $fit = $r;
                        continue;
                    }
                    if ($outer($heights, $r) <= $room + self::EPSILON) {
                        $tail = true;
                        $fit = $r;
                    }
                    break;
                }
                if ($fit >= $from) {
                    // What is left of the page goes to the last row when a spanning cell running on beside it needs it.
                    $before = $outer($heights, $fit - 1);
                    $fill = $room - $before - ($topOf($fit) ? BoxGeometry::topEdge($rowStyles[$fit]) : 0.0) - BoxGeometry::bottomEdge($rowStyles[$fit]);
                    $runsOn = false;
                    foreach ($spanning as $o => $first) {
                        $runsOn = $runsOn || ($first <= $fit && ($endOf($o) > $fit || $tail)
                            && $frags[$o]->height > self::spanHeight($first, $fit, $rowStyles, $heights, true) + self::EPSILON);
                    }
                    if ($runsOn && $fill > $heights[$fit]) {
                        $heights[$fit] = $fill;
                    }
                }
                // A spanning cell ending on a tail row must place something there, or its rows would
                // finish without it: the tail row is dropped and the cell starts again with it.
                if ($tail) {
                    foreach ($spanning as $o => $first) {
                        if ($endOf($o) !== $fit || $first > $fit) {
                            continue;
                        }
                        $res = $lay($o, self::spanHeight($first, $fit, $rowStyles, $heights, false), $fresh && $first === $from);
                        if ($res->status === Status::DoesNotFit || $res->fragment === null) {
                            $retry = true;
                        }
                    }
                    if ($retry) {
                        $cap = $fit - 1;
                    }
                }
            } while ($retry);
        }

        /** @var list<array{x: float, y: float, fragment: Fragment}> $rows row boxes, then the spanning cells over them */
        $rows = [];
        /** @var list<array{x: float, y: float, fragment: Fragment}> $overlay */
        $overlay = [];
        $state = $started;
        $ox = fn (int $o): float => $rowStyle($cells[$o]->row)->borderWidth->left->points + $rowStyle($cells[$o]->row)->padding->left->points + $slots[$cells[$o]->row][$cells[$o]->index]['x'];

        if ($fit >= $from) {
            $ys = [];
            $y = 0.0;
            for ($r = $from; $r <= $fit; $r++) {
                $ys[$r] = $y;
                $y += BoxGeometry::outerHeight($rowStyles[$r], $heights[$r], $topOf($r), true);
            }
            $rowPending = false;
            $height = 0.0;
            /** @var array<int, array{at: int, given: float, fresh: bool}> $closing done cells ending on the last row, which may have to stay open */
            $closing = [];
            foreach ($spanning as $o => $first) {
                if ($first > $fit) {
                    continue; // starts on a later page
                }
                $end = $endOf($o);
                $cellFresh = $fresh && $first === $from;
                if ($end < $fit || ($end === $fit && !$tail)) {
                    $f = $stretchCell($o, $frags[$o], self::spanHeight($first, $end, $rowStyles, $heights, false), $cellFresh, false);
                    $state[$o] = null;
                } else {
                    $given = self::spanHeight($first, $fit, $rowStyles, $heights, !$tail);
                    $res = $lay($o, $given, $cellFresh, $end > $fit);
                    if ($res->status === Status::DoesNotFit || $res->fragment === null) {
                        if ($cellFresh) {
                            return null; // unbreakable and too tall for a fresh page, like a single-row cell
                        }
                        continue; // nothing placed: the cell starts again with its next rows
                    }
                    $done = $res->status !== Status::Partial || $res->token === null;
                    $state[$o] = $done ? null : $res->token;
                    $rowPending = $rowPending || (!$done && $end === $fit);
                    if ($done && $end === $fit) {
                        $closing[$o] = ['at' => count($overlay), 'given' => $given, 'fresh' => $cellFresh];
                    }
                    $f = $res->fragment;
                }
                $overlay[] = ['x' => $ox($o), 'y' => $ys[$first] + ($topOf($first) ? BoxGeometry::topEdge($rowStyles[$first]) : 0.0), 'fragment' => $f];
            }
            $next = $rowPending ? $fit : $fit + 1;
            if ($rowPending) {
                // The row goes on in the next slice: a cell that ended on it stays open at the bottom.
                foreach ($closing as $o => $again) {
                    $entry = $overlay[$again['at']];
                    $entry['fragment'] = $lay($o, $again['given'], $again['fresh'], true)->fragment ?? $entry['fragment'];
                    $overlay[$again['at']] = $entry;
                }
            }
            /** @var array<int, list<array{x: float, fragment: Fragment}>> $extra decoration-only cells by row */
            $extra = [];
            foreach ($cells as $o => $p) {
                $end = $endOf($o);
                if ($end < $from || !array_key_exists($o, $started) || $started[$o] !== null) {
                    continue;
                }
                // Finished on an earlier slice while its rows go on: its slot still paints.
                $closed = $end < $next;
                if ($p->rowspan === 1) {
                    $extra[$p->row][] = ['x' => $slots[$p->row][$p->index]['x'], 'fragment' => $decorate($o, $heights[$p->row], $closed)];
                } else {
                    $overlay[] = [
                        'x' => $ox($o),
                        'y' => $ys[$from] + ($topOf($from) ? BoxGeometry::topEdge($rowStyles[$from]) : 0.0),
                        'fragment' => $decorate($o, self::spanHeight($from, min($fit, $end), $rowStyles, $heights, !$closed), $closed),
                    ];
                }
            }
            for ($r = $from; $r <= $fit; $r++) {
                $placedCells = $extra[$r] ?? [];
                foreach ($single[$r] as $o) {
                    $placedCells[] = ['x' => $slots[$r][$cells[$o]->index]['x'], 'fragment' => $stretchCell($o, $frags[$o], $heights[$r], $fresh && $r === $from, $r === $fit && $rowPending)];
                    $state[$o] = null;
                }
                $row = self::rowFragment($rowStyles[$r], $grid['width'], $heights[$r], $placedCells, $topOf($r), !($r === $fit && $rowPending));
                $rows[] = ['x' => 0.0, 'y' => $ys[$r], 'fragment' => $row];
                $height = $ys[$r] + $row->height;
            }
        } else {
            // Not even row $from fits: it splits in parallel with the spanning cells covering it.
            $rs = $rowStyles[$from];
            $top = $topOf($from) ? BoxGeometry::topEdge($rs) : 0.0;
            $inner = max(0.0, ($room ?? 0.0) - $top - BoxGeometry::bottomEdge($rs));
            $rowPending = false;
            $parts = array_fill_keys($single[$from], false) + array_map(static fn (): bool => true, array_filter($spanning, static fn (int $first): bool => $first === $from));
            /** @var array<int, array{fragment: Fragment, done: bool}> $laid */
            $laid = [];
            $broke = false;
            $sliceHeight = 0.0;
            foreach ($parts as $o => $isSpan) {
                $res = $lay($o, $inner, $fresh, false, false);
                if ($res->status === Status::DoesNotFit || $res->fragment === null) {
                    return null;
                }
                $done = $res->status !== Status::Partial || $res->token === null;
                $state[$o] = $done ? null : $res->token;
                $broke = $broke || !$done;
                $sliceHeight = max($sliceHeight, $res->fragment->height);
                $rowPending = $rowPending || (!$done && $endOf($o) === $from);
                $laid[$o] = ['fragment' => $res->fragment, 'done' => $done];
            }
            if ($broke) {
                $sliceHeight = $inner; // a cell that breaks fills the slice
            }
            $next = $rowPending ? $from : $from + 1;
            $sliceCells = [];
            foreach ($laid as $o => $part) {
                $f = $part['fragment'];
                if ($part['done']) {
                    $f = $stretchCell($o, $f, $sliceHeight, $fresh, $endOf($o) >= $next);
                }
                if ($parts[$o]) {
                    $overlay[] = ['x' => $ox($o), 'y' => $top, 'fragment' => $f];
                } else {
                    $sliceCells[] = ['x' => $slots[$from][$cells[$o]->index]['x'], 'fragment' => $f];
                }
            }
            foreach ($cells as $o => $p) {
                $end = $endOf($o);
                if ($end < $from || $p->row > $from || !array_key_exists($o, $started) || $started[$o] !== null) {
                    continue;
                }
                $f = $decorate($o, $sliceHeight, $end < $next);
                if ($p->rowspan === 1) {
                    $sliceCells[] = ['x' => $slots[$from][$p->index]['x'], 'fragment' => $f];
                } else {
                    $overlay[] = ['x' => $ox($o), 'y' => $top, 'fragment' => $f];
                }
            }
            $row = self::rowFragment($rs, $grid['width'], $sliceHeight, $sliceCells, $topOf($from), !$rowPending);
            $rows[] = ['x' => 0.0, 'y' => 0.0, 'fragment' => $row];
            $height = $row->height;
        }

        $fragment = new Fragment($grid['width'], $height);
        foreach ([...$rows, ...$overlay] as $child) {
            $fragment->addChild($child['x'], $child['y'], $child['fragment']);
        }

        $token = null;
        if ($next <= $last) {
            $pending = [];
            foreach ($state as $o => $t) {
                if ($cells[$o]->row + $cells[$o]->rowspan - 1 >= $next) {
                    $pending[$o] = $t;
                }
            }
            $token = new BreakToken(index: $start, parallel: $pending);
        }
        return ['height' => $height, 'fragment' => $fragment, 'next' => $next, 'token' => $token];
    }

    /**
     * Height a spanning cell gets over rows $first..$last: from $first's
     * content top to $last's content bottom, or its border-box bottom when
     * $throughBottom (the cell continues past it).
     *
     * @param array<int, ComputedStyle> $rowStyles
     * @param array<int, float> $heights row content heights
     */
    private static function spanHeight(int $first, int $last, array $rowStyles, array $heights, bool $throughBottom): float
    {
        $height = 0.0;
        for ($r = $first; $r <= $last; $r++) {
            $height += BoxGeometry::outerHeight($rowStyles[$r], $heights[$r], $r !== $first, $r !== $last || $throughBottom);
        }
        return $height;
    }

    /**
     * Lays cells $js of a row out at unbounded height: their fragments and the
     * tallest one's height.
     *
     * @param array<int, TableCell> $cellsOf
     * @param list<int> $js
     * @param array<int, array{x: float, width: float}> $slots
     * @return array{height: float, fragments: array<int, Fragment>}
     */
    private function measureCells(array $cellsOf, array $js, array $slots, ComputedStyle $rowStyle, bool $fresh, LayoutEngine $engine): array
    {
        return ParallelSplit::measure(
            $js,
            0.0,
            fn (int $j): Fragment => $this->layoutCell($cellsOf[$j], $j, $slots[$j]['width'], $rowStyle, new Constraints($slots[$j]['width'], null, $fresh), null, $engine)->fragment
                ?? new Fragment($slots[$j]['width'], 0.0),
            static fn (int $j, Fragment $f): float => $f->height,
        );
    }

    /**
     * A cell's fragment $f at $height: laid out again at that height (from
     * $token, where $f resumed) when stretching changes its painting, else boxed.
     * $open: the cell's rows go on in the next slice, so it draws no bottom edge.
     */
    private function stretch(TableCell $cell, int $j, Fragment $f, float $width, float $height, ComputedStyle $rowStyle, bool $fresh, LayoutEngine $engine, ?BreakToken $token = null, bool $open = false): Fragment
    {
        $paints = $this->paintsStretched($cell, $j, $rowStyle, $engine);
        if ($f->height >= $height - self::EPSILON && !($open && $paints)) {
            return $f;
        }
        if ($paints) {
            return $this->layoutCell($cell, $j, $width, $rowStyle, new Constraints($width, null, $fresh, $height, $height, $open), $token, $engine)->fragment ?? $f;
        }
        $box = new Fragment($f->width, $height);
        $box->addChild(0.0, 0.0, $f);
        return $box;
    }

    /**
     * Parallel split of one row in $height. $tokens null = fresh split (all
     * cells start); otherwise only cells with a non-null token continue.
     * Returns null when a cell cannot place anything.
     *
     * @param array{width: float, widths: list<float>, offsets: list<float>} $grid
     * @param ?array<int, ?BreakToken> $tokens
     * @return ?array{height: float, tokens: array<int, ?BreakToken>, fragment: Fragment}
     */
    private function splitRow(Table $table, TableRow $row, int $r, array $grid, ComputedStyle $style, ?float $height, bool $fresh, ?array $tokens, LayoutEngine $engine): ?array
    {
        return $this->inRow($row, $r, $style, $engine, function (ComputedStyle $rowStyle) use ($table, $row, $r, $grid, $height, $fresh, $tokens, $engine): ?array {
            $first = $tokens === null;
            $room = $height === null
                ? null
                : max(0.0, $height - ($first ? BoxGeometry::topEdge($rowStyle) : 0.0) - BoxGeometry::bottomEdge($rowStyle));
            $slots = self::slots($table, $r, $grid);
            $cellsOf = $row->cells();
            /** @var array<int, ?BreakToken> $resumed */
            $resumed = [];
            $split = ParallelSplit::split(
                array_map(static fn (array $s): array => ['x' => $s['x'], 'top' => 0.0, 'bottom' => 0.0], $slots),
                $room,
                $tokens,
                function (int $j, ?float $h, ?BreakToken $resume) use ($cellsOf, $slots, $rowStyle, $fresh, $engine, &$resumed): LayoutResult {
                    $resumed[$j] = $resume;
                    return $this->layoutCell($cellsOf[$j], $j, $slots[$j]['width'], $rowStyle, new Constraints($slots[$j]['width'], $h, $fresh, null, $h), $resume, $engine);
                },
            );
            if ($split === null) {
                return null;
            }
            // Every slot paints the whole slice: a cell that ended is stretched, one that ended earlier is only its box.
            $pending = ParallelSplit::pending($split['tokens']);
            $cells = [];
            $laid = array_keys($resumed);
            $at = 0;
            foreach ($slots as $j => $slot) {
                $cell = $cellsOf[$j];
                if (in_array($j, $laid, true)) {
                    $f = $split['cells'][$at++]['fragment'];
                    if ($split['tokens'][$j] === null) {
                        $f = $this->stretch($cell, $j, $f, $slot['width'], $split['height'], $rowStyle, $fresh, $engine, $resumed[$j], $pending);
                    }
                } else {
                    $f = new Fragment($slot['width'], $split['height']);
                    BoxGeometry::decorate($f, $engine->styleOf($this->prepare($cell, $engine->context()->theme), $rowStyle, $j), false, !$pending);
                }
                $cells[] = ['x' => $slot['x'], 'fragment' => $f];
            }
            $fragment = self::rowFragment($rowStyle, $grid['width'], $split['height'], $cells, $first, !$pending);
            return ['height' => $fragment->height, 'tokens' => $split['tokens'], 'fragment' => $fragment];
        });
    }

    /**
     * A row box (one slice of it for split rows) with its cells placed inside
     * the row's border and padding.
     *
     * @param list<array{x: float, y?: float, fragment: Fragment}> $cells
     */
    private static function rowFragment(ComputedStyle $rowStyle, float $width, float $contentHeight, array $cells, bool $first, bool $last): Fragment
    {
        $f = new Fragment($width, BoxGeometry::outerHeight($rowStyle, $contentHeight, $first, $last));
        BoxGeometry::decorate($f, $rowStyle, $first, $last);
        $ox = $rowStyle->borderWidth->left->points + $rowStyle->padding->left->points;
        $oy = $first ? BoxGeometry::topEdge($rowStyle) : 0.0;
        foreach ($cells as $cell) {
            $f->addChild($ox + $cell['x'], $oy, $cell['fragment']);
        }
        return $f;
    }

    /** Lays a cell out at exactly $width, with the theme's cell defaults. */
    private function layoutCell(TableCell $cell, int $j, float $width, ComputedStyle $rowStyle, Constraints $c, ?BreakToken $token, LayoutEngine $engine): LayoutResult
    {
        $prepared = $this->prepare($cell, $engine->context()->theme);
        // The column decides the cell's width: a width/min/max that disagrees is pinned to it. Pinning
        // clones the cell, and the clone must stay marked as a table cell or it warns as a stray one.
        $pinned = ExactWidth::pin($prepared, $width, $engine->styleOf($prepared, $rowStyle, $j));
        if ($pinned instanceof TableCell && $pinned !== $prepared) {
            $this->inTable[$pinned] = true;
        }
        return ExactWidth::layout($pinned, $width, $rowStyle, $c, $token, $engine, $j);
    }

    /**
     * True when stretching the cell to its row's height changes its painting:
     * a background or border, a non-start justify, or a growing (or not yet
     * rendered Component) child.
     */
    private function paintsStretched(TableCell $cell, int $j, ComputedStyle $rowStyle, LayoutEngine $engine): bool
    {
        $prepared = $this->prepare($cell, $engine->context()->theme);
        $style = $engine->styleOf($prepared, $rowStyle, $j);
        $b = $style->borderWidth;
        if ($style->background !== null || $style->justify !== Justify::Start
            || $b->top->points > 0.0 || $b->right->points > 0.0 || $b->bottom->points > 0.0 || $b->left->points > 0.0) {
            return true;
        }
        foreach ($prepared->children() as $i => $child) {
            if (!$child instanceof Node || $engine->styleOf($child, $style, $i)->grow > 0.0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Runs $fn with the row's computed style (the cells' parent) and its path entry pushed.
     *
     * @template T
     * @param \Closure(ComputedStyle): T $fn
     * @return T
     */
    private function inRow(TableRow $row, int $r, ComputedStyle $style, LayoutEngine $engine, \Closure $fn): mixed
    {
        $rowStyle = $engine->styleOf($row, $style, $r);
        $ctx = $engine->context();
        $ctx->pushPath(sprintf('tr[%d]', $r));
        try {
            return $fn($rowStyle);
        } finally {
            $ctx->popPath();
        }
    }

    /**
     * x and width of each cell of row $r (all-row index), keyed by cell index,
     * at its grid column; a colspan cell spans its columns' widths.
     *
     * @param array{width: float, widths: list<float>, offsets: list<float>} $grid
     * @return array<int, array{x: float, width: float}>
     */
    private static function slots(Table $table, int $r, array $grid): array
    {
        $slots = [];
        foreach (TableGrid::of($table)->placements[$r] ?? [] as $p) {
            $slots[$p->index] = [
                'x' => $grid['offsets'][$p->col] ?? 0.0,
                'width' => array_sum(array_slice($grid['widths'], $p->col, $p->colspan)),
            ];
        }
        return $slots;
    }

    /** Cache key for the inherited text properties, the only ones that change intrinsic sizes. */
    private static function styleKey(ComputedStyle $style): string
    {
        return implode("\0", [
            $style->fontFamily,
            (string) $style->fontSize,
            (string) $style->fontWeight,
            $style->italic ? 'i' : '',
            (string) $style->letterSpacing,
            (string) $style->lineHeight,
        ]);
    }

    /**
     * One track per column: the column count is the grid's (TableGrid, which places cells by
     * colspan and rowspan); missing tracks are 1fr, extra ones ignored.
     *
     * @return list<Length|Percent|Fr|Auto>
     */
    public static function trackList(Table $table): array
    {
        $n = TableGrid::of($table)->columns;
        $given = $table->trackList()->tracks ?? [];
        $tracks = [];
        for ($k = 0; $k < $n; $k++) {
            $tracks[] = $given[$k] ?? Fr::of(1);
        }
        return $tracks;
    }

    /**
     * Theme cell padding unless the cell (or its named style) sets padding;
     * th cells are bold unless they set a weight.
     */
    private function prepare(TableCell $cell, Theme $theme): TableCell
    {
        if (!isset($this->prepared[$cell])) {
            $prepared = self::withThemeDefaults($cell, $theme);
            $this->prepared[$cell] = $prepared;
            $this->inTable[$prepared] = true; // the engine lays this one out; it is not a stray cell
        }
        return $this->prepared[$cell];
    }

    // StyleResolver applies the same defaults as its lowest layer; applying them twice changes nothing.
    private static function withThemeDefaults(TableCell $cell, Theme $theme): TableCell
    {
        $source = $cell;
        $own = $cell->style();
        $named = new Style();
        $name = $cell->styleName();
        if ($name !== null) {
            try {
                $named = $theme->named($name);
            } catch (UnknownAttribute) {
                // reported with its path when the engine resolves the cell's style
            }
        }
        if ($own->padding === null && $named->padding === null) {
            $cell = $cell->padding($theme->cellPadding);
        }
        if ($cell->header() && $own->fontWeight === null && $named->fontWeight === null) {
            $cell = $cell->weight(700);
        }
        // Always a distinct instance: the source cell is never marked inTable, so a bare reuse still warns.
        return $cell === $source ? clone $cell : $cell;
    }

    /** The row at all-row index $r (header rows first). */
    private static function rowAt(Table $table, int $r): TableRow
    {
        $headers = $table->headerRows();
        return $headers[$r] ?? $table->bodyRows()[$r - count($headers)];
    }

    /** @return list<TableRow> */
    private static function allRows(Table $table): array
    {
        return [...$table->headerRows(), ...$table->bodyRows()];
    }

    private function warnStray(TableCell $cell, LayoutEngine $engine): void
    {
        if (!isset($this->inTable[$cell]) && !isset($this->strayWarned[$cell])) {
            $this->strayWarned[$cell] = true;
            $engine->context()->warn('A table cell outside a table is laid out as a column');
        }
    }

    private function column(TableCell $cell): View
    {
        return $this->columns[$cell] ??= View::make(...$cell->children());
    }

    private static function table(Node $node): Table
    {
        if ($node instanceof Table) {
            return $node;
        }
        throw new \LogicException(sprintf('TableLayouter cannot lay out %s.', $node::class));
    }
}
