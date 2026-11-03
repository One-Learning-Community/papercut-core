<?php

declare(strict_types=1);

/**
 * Renders bench/transcript.php (a 10-page table) once to warm up, then $argv[1] (default 5) timed runs, and prints
 * JSON: {median_ms, runs_ms[], pages, bytes, opcache, php, peak_mb, html_median_ms, article_median_ms,
 * article_peak_mb, article_pages}. html_median_ms times toHtml() of the transcript, and article_* the PDF of
 * bench/article.php (prose), each the same way. A peak is the most memory one run allocated, in MB.
 *
 * Run with Xdebug off and OPcache on:
 *   php -d xdebug.mode=off -d opcache.enable_cli=1 bench/run.php
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/lib.php';

$runs = max(1, (int) ($argv[1] ?? 5));
$transcript = static fn (): Papercut\Document => require __DIR__ . '/transcript.php';
$article = static fn (): Papercut\Document => require __DIR__ . '/article.php';

$pdf = papercut_bench(static fn (): string => $transcript()->toPdf()->bytes(), $runs);
$html = papercut_bench(static fn (): string => $transcript()->toHtml()->bytes(), $runs);
$prose = papercut_bench(static fn (): string => $article()->toPdf()->bytes(), $runs);

echo json_encode([
    'median_ms' => $pdf['median_ms'],
    'runs_ms' => $pdf['runs_ms'],
    'pages' => papercut_bench_pages($pdf['result']),
    'bytes' => strlen($pdf['result']),
    'opcache' => function_exists('opcache_get_status') && (opcache_get_status(false)['opcache_enabled'] ?? false),
    'php' => PHP_VERSION,
    'peak_mb' => $pdf['peak_mb'],
    'html_median_ms' => $html['median_ms'],
    'article_median_ms' => $prose['median_ms'],
    'article_peak_mb' => $prose['peak_mb'],
    'article_pages' => papercut_bench_pages($prose['result']),
], JSON_PRETTY_PRINT), "\n";
