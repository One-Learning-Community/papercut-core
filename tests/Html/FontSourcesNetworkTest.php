<?php

declare(strict_types=1);

namespace Papercut\Tests\Html;

use Papercut\Document;
use Papercut\Export\{FontMode, HtmlOptions};
use Papercut\Html\GoogleFonts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Live check that the stylesheet URLs we build are accepted. Run with: PAPERCUT_NETWORK_TESTS=1 vendor/bin/phpunit tests/Html/FontSourcesNetworkTest.php */
final class FontSourcesNetworkTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function cases(): iterable
    {
        foreach (['invoice', 'report', 'transcript'] as $example) {
            foreach (['https://fonts.googleapis.com', 'https://fonts.bunny.net'] as $host) {
                yield "$example @ $host" => [$example, $host];
            }
        }
    }

    #[DataProvider('cases')]
    public function test_stylesheets_answer_200(string $example, string $host): void
    {
        if (getenv('PAPERCUT_NETWORK_TESTS') !== '1') {
            self::markTestSkipped('Set PAPERCUT_NETWORK_TESTS=1 to run the live font host check.');
        }
        $document = (static fn () => require __DIR__ . "/../../../../examples/core/documents/$example.php")();
        self::assertInstanceOf(Document::class, $document);
        $html = $document->toHtml(new HtmlOptions(fullPage: true, fontMode: FontMode::Sources, fontSources: [new GoogleFonts(host: $host)]))->bytes();

        self::assertGreaterThan(0, preg_match_all('/<link rel="stylesheet" href="([^"]+)"/', $html, $m));
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 15, 'header' => "User-Agent: Mozilla/5.0 (Macintosh) AppleWebKit/537.36 Chrome/120 Safari/537.36\r\n"]]);
        foreach ($m[1] as $href) {
            $url = html_entity_decode($href);
            @file_get_contents($url, false, $context);
            $status = $http_response_header[0] ?? '(no response)';
            self::assertStringContainsString(' 200', $status, $url);
        }
    }
}
