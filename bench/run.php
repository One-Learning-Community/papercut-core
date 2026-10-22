<?php

declare(strict_types=1);

/**
 * Renders bench/transcript.php once to warm up, then $argv[1] (default 5) timed
 * runs, and prints JSON: {median_ms, runs_ms[], pages, bytes, opcache, php, html_median_ms}.
 * html_median_ms times toHtml() (default HtmlOptions) of the same document the
 * same way: one warm-up, then the same number of timed runs.
 *
 * Run with Xdebug off and OPcache on:
 *   php -d xdebug.mode=off -d opcache.enable_cli=1 bench/run.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

$runs = max(1, (int) ($argv[1] ?? 5));
$build = static fn (): Papercut\Document => require __DIR__ . '/transcript.php';

$pdf = $build()->toPdf()->bytes(); // warm-up: autoload, OPcache, font parsing
$times = [];
for ($i = 0; $i < $runs; $i++) {
    $start = hrtime(true);
    $pdf = $build()->toPdf()->bytes();
    $times[] = (hrtime(true) - $start) / 1e6;
}

$median = static function (array $times): float {
    sort($times);
    $mid = intdiv(count($times), 2);
    return count($times) % 2 === 1 ? $times[$mid] : ($times[$mid - 1] + $times[$mid]) / 2;
};

$build()->toHtml(); // warm-up for the HTML path
$htmlTimes = [];
for ($i = 0; $i < $runs; $i++) {
    $start = hrtime(true);
    $build()->toHtml();
    $htmlTimes[] = (hrtime(true) - $start) / 1e6;
}

preg_match_all('#/Type\s*/Page[^s]#', $pdf, $m);

echo json_encode([
    'median_ms' => round($median($times), 2),
    'runs_ms' => array_map(static fn (float $t): float => round($t, 2), $times),
    'pages' => count($m[0]),
    'bytes' => strlen($pdf),
    'opcache' => function_exists('opcache_get_status') && (opcache_get_status(false)['opcache_enabled'] ?? false),
    'php' => PHP_VERSION,
    'html_median_ms' => round($median($htmlTimes), 2),
], JSON_PRETTY_PRINT), "\n";
