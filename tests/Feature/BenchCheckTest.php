<?php

declare(strict_types=1);

namespace Papercut\Tests\Feature;

use PHPUnit\Framework\TestCase;

/** bench/check.php, the CI benchmark gate, run as CI runs it: a subprocess fed run.php's JSON. */
final class BenchCheckTest extends TestCase
{
    private string $baseline;
    private string $result;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/papercut-bench-check-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $this->baseline = $base . '-baseline.json';
        $this->result = $base . '-result.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->baseline);
        @unlink($this->result);
    }

    /**
     * @param array<string, string> $env
     * @return array{exit: int, out: string}
     */
    private function check(string $result, array $env = []): array
    {
        file_put_contents($this->result, $result);
        $env += ['PAPERCUT_BASELINE' => $this->baseline, 'PAPERCUT_BENCH_MACHINE' => 'test', 'PATH' => (string) getenv('PATH')];
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_startup_errors=0', __DIR__ . '/../../bench/check.php', $this->result],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env,
        );
        self::assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['exit' => proc_close($proc), 'out' => $out];
    }

    private function writeBaseline(float $median): void
    {
        file_put_contents($this->baseline, json_encode(['machines' => ['test' => ['median_ms' => $median]]]));
    }

    private static function benchResult(float $median, int $pages = 10, ?float $html = null): string
    {
        $result = ['median_ms' => $median, 'runs_ms' => [$median], 'pages' => $pages, 'bytes' => 1, 'opcache' => true, 'php' => '8.4.0'];
        if ($html !== null) {
            $result['html_median_ms'] = $html;
        }
        return (string) json_encode($result);
    }

    public function test_missing_baseline_writes_an_entry_and_passes(): void
    {
        $run = $this->check(self::benchResult(42.5));
        self::assertSame(0, $run['exit'], $run['out']);
        $written = json_decode((string) file_get_contents($this->baseline), true);
        self::assertIsArray($written);
        self::assertSame(42.5, $written['machines']['test']['median_ms']);
        self::assertTrue($written['machines']['test']['opcache']);
        self::assertStringNotContainsString('::warning', $run['out']);
    }

    public function test_writing_a_baseline_on_ci_warns_that_the_gate_is_unarmed(): void
    {
        $run = $this->check(self::benchResult(42.5), ['CI' => 'true']);
        self::assertSame(0, $run['exit'], $run['out']);
        self::assertStringContainsString('::warning', $run['out']);
        self::assertStringContainsString('unarmed', $run['out']);
    }

    public function test_more_than_20_percent_slower_fails(): void
    {
        $this->writeBaseline(100.0);
        self::assertSame(1, $this->check(self::benchResult(121.0))['exit']);
        self::assertSame(0, $this->check(self::benchResult(119.0))['exit']);
    }

    public function test_html_more_than_20_percent_slower_fails(): void
    {
        file_put_contents($this->baseline, json_encode(['machines' => ['test' => ['median_ms' => 100.0, 'html_median_ms' => 20.0]]]));
        $run = $this->check(self::benchResult(100.0, html: 24.5));
        self::assertSame(1, $run['exit'], $run['out']);
        self::assertStringContainsString('check: FAIL: html median 24.50 ms exceeds 24.00 ms (baseline 20.00 ms + 20%)', $run['out']);
        self::assertSame(0, $this->check(self::benchResult(100.0, html: 23.0))['exit']);
    }

    public function test_missing_html_baseline_is_added_to_the_entry(): void
    {
        $this->writeBaseline(100.0);
        $run = $this->check(self::benchResult(100.0, html: 18.25));
        self::assertSame(0, $run['exit'], $run['out']);
        self::assertStringContainsString('no html baseline', $run['out']);
        $written = json_decode((string) file_get_contents($this->baseline), true);
        self::assertIsArray($written);
        self::assertSame(18.25, $written['machines']['test']['html_median_ms']);
        self::assertEquals(100.0, $written['machines']['test']['median_ms']);
    }

    public function test_result_without_html_key_is_unchanged_behavior(): void
    {
        $contents = json_encode(['machines' => ['test' => ['median_ms' => 100.0, 'html_median_ms' => 20.0]]]);
        file_put_contents($this->baseline, $contents);
        $run = $this->check(self::benchResult(100.0));
        self::assertSame(0, $run['exit'], $run['out']);
        self::assertStringNotContainsString('html', $run['out']);
        self::assertSame($contents, file_get_contents($this->baseline));
    }

    public function test_a_document_that_is_not_ten_pages_fails(): void
    {
        $this->writeBaseline(100.0);
        $run = $this->check(self::benchResult(50.0, pages: 11));
        self::assertSame(1, $run['exit']);
        self::assertStringContainsString('11 pages', $run['out']);
    }

    public function test_every_timing_and_memory_peak_is_gated(): void
    {
        file_put_contents($this->baseline, json_encode(['machines' => ['test' => ['median_ms' => 100.0, 'peak_mb' => 10.0, 'article_median_ms' => 50.0, 'article_peak_mb' => 20.0]]]));
        $result = static fn (array $over): string => (string) json_encode($over + ['median_ms' => 100.0, 'pages' => 10, 'peak_mb' => 10.0, 'article_median_ms' => 50.0, 'article_peak_mb' => 20.0, 'article_pages' => 20]);

        self::assertSame(0, $this->check($result([]))['exit']);
        $run = $this->check($result(['article_peak_mb' => 24.5]));
        self::assertSame(1, $run['exit'], $run['out']);
        self::assertStringContainsString('check: FAIL: article peak 24.50 MB exceeds 24.00 MB (baseline 20.00 MB + 20%)', $run['out']);
        $run = $this->check($result(['peak_mb' => 12.5]));
        self::assertStringContainsString('check: FAIL: peak 12.50 MB exceeds 12.00 MB', $run['out']);
        $run = $this->check($result(['article_median_ms' => 61.0]));
        self::assertStringContainsString('check: FAIL: article median 61.00 ms exceeds 60.00 ms', $run['out']);
    }

    public function test_a_new_figure_is_added_to_the_entry(): void
    {
        $this->writeBaseline(100.0);
        $run = $this->check((string) json_encode(['median_ms' => 100.0, 'pages' => 10, 'article_peak_mb' => 19.3]));
        self::assertSame(0, $run['exit'], $run['out']);
        self::assertStringContainsString('no article peak baseline', $run['out']);
        $written = json_decode((string) file_get_contents($this->baseline), true);
        self::assertIsArray($written);
        self::assertSame(19.3, $written['machines']['test']['article_peak_mb']);
    }

    public function test_a_fresh_entry_records_every_figure(): void
    {
        $run = $this->check((string) json_encode(['median_ms' => 100.0, 'toc_off_median_ms' => 40.0, 'peak_mb' => 30.0]));
        self::assertSame(0, $run['exit'], $run['out']);
        $written = json_decode((string) file_get_contents($this->baseline), true);
        self::assertIsArray($written);
        self::assertEquals(40.0, $written['machines']['test']['toc_off_median_ms']);
        self::assertEquals(30.0, $written['machines']['test']['peak_mb']);
    }

    public function test_the_article_must_keep_its_page_count(): void
    {
        $this->writeBaseline(100.0);
        $run = $this->check((string) json_encode(['median_ms' => 100.0, 'pages' => 10, 'article_pages' => 21]));
        self::assertSame(1, $run['exit']);
        self::assertStringContainsString('article benchmark document has 21 pages, expected 20', $run['out']);
    }

    public function test_a_result_without_pages_skips_the_page_and_target_checks(): void
    {
        $this->writeBaseline(400.0);
        $run = $this->check((string) json_encode(['median_ms' => 400.0]));
        self::assertSame(0, $run['exit'], $run['out']);
        self::assertStringNotContainsString('target', $run['out']);
    }

    public function test_malformed_input_exits_2(): void
    {
        self::assertSame(2, $this->check('not json')['exit']);
        self::assertSame(2, $this->check('{"pages": 10}')['exit']);
    }
}
