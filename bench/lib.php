<?php

declare(strict_types=1);

/** Timing helpers shared by bench/run.php and packages/docx/bench/run.php. */

/**
 * Runs $export once to warm up (autoload, OPcache, font parsing), then $runs timed times. Returns the median and every
 * run in milliseconds, the most memory one run allocated beyond what was in use before it (MB), and the last result.
 *
 * @template T
 * @param \Closure(): T $export
 * @return array{median_ms: float, runs_ms: list<float>, peak_mb: float, result: T}
 */
function papercut_bench(\Closure $export, int $runs): array
{
    $result = $export();
    $times = [];
    $peak = 0;
    for ($i = 0; $i < $runs; $i++) {
        gc_collect_cycles();
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $start = hrtime(true);
        $result = $export();
        $times[] = (hrtime(true) - $start) / 1e6;
        $peak = max($peak, memory_get_peak_usage() - $before);
    }
    $sorted = $times;
    sort($sorted);
    $mid = intdiv(count($sorted), 2);
    $median = count($sorted) % 2 === 1 ? $sorted[$mid] : ($sorted[$mid - 1] + $sorted[$mid]) / 2;

    return [
        'median_ms' => round($median, 2),
        'runs_ms' => array_map(static fn (float $t): float => round($t, 2), $times),
        'peak_mb' => round($peak / 1048576, 1),
        'result' => $result,
    ];
}

/** The page count of a PDF's bytes. */
function papercut_bench_pages(string $pdf): int
{
    return preg_match_all('#/Type\s*/Page[^s]#', $pdf);
}
