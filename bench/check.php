<?php

declare(strict_types=1);

/**
 * CI benchmark gate. Reads bench/run.php's JSON (file argument or stdin) and
 * compares its median with the committed baseline for this machine class
 * (env PAPERCUT_BENCH_MACHINE, default "local") in bench/baseline.json.
 *
 * When the result has html_median_ms (HTML export timing), it is gated the same
 * way against the entry's html_median_ms; an entry without one gets it written
 * from this run. Results without the key are checked as before.
 *
 * Exit 1 when the median exceeds 1.2x the baseline or the document is not
 * 10 pages. Missing the spec target of 150 ms (spec §3.5) is reported as a
 * warning: it was set for a developer machine, and CI hardware varies. A
 * machine class with no baseline yet gets one written from this run (commit
 * it to arm the gate).
 *
 * The baseline file is bench/baseline.json unless env PAPERCUT_BASELINE names
 * another. Exit codes: 0 pass, 1 gate failed, 2 unreadable input.
 *
 *   php -d xdebug.mode=off -d opcache.enable_cli=1 bench/run.php | php bench/check.php
 */

const TOLERANCE = 1.2;
const TARGET_MS = 150.0;

$input = isset($argv[1]) ? file_get_contents($argv[1]) : stream_get_contents(STDIN);
$result = json_decode((string) $input, true);
if (!is_array($result) || !is_numeric($result['median_ms'] ?? null)) {
    fwrite(STDERR, "check: no benchmark result (expected bench/run.php JSON)\n");
    exit(2);
}
$median = (float) $result['median_ms'];
$machine = getenv('PAPERCUT_BENCH_MACHINE') ?: 'local';
$path = getenv('PAPERCUT_BASELINE') ?: __DIR__ . '/baseline.json';
$baseline = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
$baseline = is_array($baseline) ? $baseline : ['machines' => []];

$failures = [];
if (($result['pages'] ?? null) !== 10) {
    $failures[] = sprintf('benchmark document has %s pages, expected 10', var_export($result['pages'] ?? null, true));
}
if ($median >= TARGET_MS) {
    fwrite(STDERR, sprintf("check: WARN: median %.2f ms misses the %.0f ms target\n", $median, TARGET_MS));
}

$entry = $baseline['machines'][$machine] ?? null;
if (!is_array($entry) || !is_numeric($entry['median_ms'] ?? null)) {
    $baseline['machines'][$machine] = [
        'median_ms' => $median,
        'php' => $result['php'] ?? PHP_VERSION,
        'opcache' => (bool) ($result['opcache'] ?? false),
        'recorded' => gmdate('Y-m-d'),
    ];
    if (is_numeric($result['html_median_ms'] ?? null)) {
        $baseline['machines'][$machine]['html_median_ms'] = (float) $result['html_median_ms'];
    }
    ksort($baseline['machines']);
    $wroteEntry = true;
    file_put_contents($path, json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    printf("check: no baseline for machine \"%s\"; wrote %.2f ms to %s\n", $machine, $median, $path);
    if (getenv('CI') !== false && getenv('CI') !== '') {
        printf("::warning title=Benchmark gate unarmed::No committed baseline for machine \"%s\", so the 20%% regression gate did not run. Commit the baseline.json from this job's \"bench\" artifact to arm it.\n", $machine);
    }
} else {
    $limit = (float) $entry['median_ms'] * TOLERANCE;
    printf("check: median %.2f ms, baseline %.2f ms (%s), limit %.2f ms\n", $median, $entry['median_ms'], $machine, $limit);
    if ($median > $limit) {
        $failures[] = sprintf('median %.2f ms is more than %.0f%% slower than the baseline %.2f ms', $median, (TOLERANCE - 1) * 100, $entry['median_ms']);
    }
}

$htmlMedian = is_numeric($result['html_median_ms'] ?? null) ? (float) $result['html_median_ms'] : null;
$entry = $baseline['machines'][$machine];
if ($htmlMedian !== null && is_array($entry)) {
    if (!is_numeric($entry['html_median_ms'] ?? null)) {
        if (!isset($wroteEntry)) { // a freshly written entry already carries it
            $baseline['machines'][$machine]['html_median_ms'] = $htmlMedian;
            file_put_contents($path, json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            printf("check: no html baseline for machine \"%s\"; wrote %.2f ms to %s\n", $machine, $htmlMedian, $path);
        }
    } else {
        $htmlLimit = (float) $entry['html_median_ms'] * TOLERANCE;
        printf("check: html median %.2f ms, baseline %.2f ms (%s), limit %.2f ms\n", $htmlMedian, $entry['html_median_ms'], $machine, $htmlLimit);
        if ($htmlMedian > $htmlLimit) {
            $failures[] = sprintf('html median %.2f ms exceeds %.2f ms (baseline %.2f ms + %.0f%%)', $htmlMedian, $htmlLimit, $entry['html_median_ms'], (TOLERANCE - 1) * 100);
        }
    }
}

foreach ($failures as $f) {
    fwrite(STDERR, "check: FAIL: $f\n");
}
exit($failures === [] ? 0 : 1);
