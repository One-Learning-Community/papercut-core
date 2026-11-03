<?php

declare(strict_types=1);

/**
 * CI benchmark gate. Reads bench/run.php's JSON (file argument or stdin) and
 * compares its median with the committed baseline for this machine class
 * (env PAPERCUT_BENCH_MACHINE, default "local") in bench/baseline.json.
 *
 * Every other timing in the result (html_median_ms, article_median_ms, a Word
 * benchmark's toc_off_median_ms) and every memory peak (peak_mb, article_peak_mb)
 * is gated the same way against the entry's value of the same name; an entry
 * without one gets it written from this run.
 *
 * With env PAPERCUT_BENCH_TIMINGS=warn (set in CI), a slow timing is a warning
 * and only memory peaks and page counts fail.
 *
 * Exit 1 when a figure exceeds 1.2x its baseline or a benchmark document's page
 * count changed (EXPECTED_PAGES, checked when the result reports it). Missing the spec target of 150 ms (spec §3.5) is reported as a
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
/** Page counts each benchmark document must keep, checked when the result reports them. */
const EXPECTED_PAGES = ['pages' => 10, 'article_pages' => 20];

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
// PAPERCUT_BENCH_TIMINGS=warn reports slow timings without failing: shared CI runners differ in speed from run to
// run, while memory peaks and page counts don't, so CI fails on those alone.
$timingsWarn = getenv('PAPERCUT_BENCH_TIMINGS') === 'warn';
$slow = static function (string $message) use (&$failures, $timingsWarn): void {
    if ($timingsWarn) {
        fwrite(STDERR, "check: WARN: $message\n");
        if (getenv('CI') !== false && getenv('CI') !== '') {
            printf("::warning title=Benchmark slower::%s\n", $message);
        }
        return;
    }
    $failures[] = $message;
};
foreach (EXPECTED_PAGES as $key => $pages) {
    if (array_key_exists($key, $result) && $result[$key] !== $pages) {
        $what = $key === 'pages' ? 'benchmark document' : str_replace('_pages', '', $key) . ' benchmark document';
        $failures[] = sprintf('%s has %s pages, expected %d', $what, var_export($result[$key], true), $pages);
    }
}
if (array_key_exists('pages', $result) && $median >= TARGET_MS) {
    fwrite(STDERR, sprintf("check: WARN: median %.2f ms misses the %.0f ms target\n", $median, TARGET_MS));
}

$entry = $baseline['machines'][$machine] ?? null;
$wroteEntry = false;
if (!is_array($entry) || !is_numeric($entry['median_ms'] ?? null)) {
    $baseline['machines'][$machine] = [
        'median_ms' => $median,
        'php' => $result['php'] ?? PHP_VERSION,
        'opcache' => (bool) ($result['opcache'] ?? false),
        'recorded' => gmdate('Y-m-d'),
    ];
    foreach (array_keys(metrics($result)) as $key) {
        $baseline['machines'][$machine][$key] = (float) $result[$key];
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
        $slow(sprintf('median %.2f ms is more than %.0f%% slower than the baseline %.2f ms', $median, (TOLERANCE - 1) * 100, $entry['median_ms']));
    }
}

// Every other timing (*_median_ms) and memory peak (peak_mb, *_peak_mb) is gated the same way; one the entry lacks is added.
if (!$wroteEntry) {
    $entry = $baseline['machines'][$machine];
    foreach (metrics($result) as $key => [$label, $unit]) {
        $value = (float) $result[$key];
        if (!is_numeric($entry[$key] ?? null)) {
            $baseline['machines'][$machine][$key] = $value;
            file_put_contents($path, json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            printf("check: no %s baseline for machine \"%s\"; wrote %.2f %s to %s\n", preg_replace('/ median$/', '', $label), $machine, $value, $unit, $path);
            continue;
        }
        $limit = (float) $entry[$key] * TOLERANCE;
        printf("check: %s %.2f %s, baseline %.2f %s (%s), limit %.2f %s\n", $label, $value, $unit, $entry[$key], $unit, $machine, $limit, $unit);
        if ($value > $limit) {
            $message = sprintf('%s %.2f %s exceeds %.2f %s (baseline %.2f %s + %.0f%%)', $label, $value, $unit, $limit, $unit, $entry[$key], $unit, (TOLERANCE - 1) * 100);
            $unit === 'ms' ? $slow($message) : $failures[] = $message;
        }
    }
}

foreach ($failures as $f) {
    fwrite(STDERR, "check: FAIL: $f\n");
}
exit($failures === [] ? 0 : 1);

/**
 * The result's gated figures besides median_ms, each with its label and unit: "html_median_ms" is "html median" in ms,
 * "article_peak_mb" is "article peak" in MB.
 *
 * @param array<mixed> $result
 * @return array<string, array{string, string}>
 */
function metrics(array $result): array
{
    $out = [];
    foreach ($result as $key => $value) {
        if (!is_string($key) || !is_numeric($value) || $key === 'median_ms') {
            continue;
        }
        if (preg_match('/^(?:([a-z0-9_]+)_)?(median_ms|peak_mb)$/', $key, $m) === 1) {
            $name = str_replace('_', ' ', $m[1] ?? '');
            $kind = $m[2] === 'median_ms' ? 'median' : 'peak';
            $out[$key] = [trim("$name $kind"), $m[2] === 'median_ms' ? 'ms' : 'MB'];
        }
    }
    return $out;
}
