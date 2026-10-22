<?php

declare(strict_types=1);

namespace Papercut\Tests\Html;

use Papercut\Document;
use Papercut\Export\HtmlOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Full-page HTML (FontMode::None, so the files stay small) for every visual fixture and example document,
 * canonicalised through the HTML5 parser. Refresh with: UPDATE_HTML_GOLDEN=1 vendor/bin/phpunit tests/Html/GoldenHtmlTest.php
 */
final class GoldenHtmlTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function documents(): iterable
    {
        foreach (['Visual/fixtures' => 'fixtures', '../../../examples/core/documents' => 'examples'] as $dir => $kind) {
            $files = glob(__DIR__ . "/../$dir/*.php") ?: [];
            sort($files);
            foreach ($files as $file) {
                yield $kind . '-' . basename($file, '.php') => [$file, $kind];
            }
        }
    }

    #[DataProvider('documents')]
    public function test_matches_golden(string $file, string $kind): void
    {
        $document = require $file;
        self::assertInstanceOf(Document::class, $document);
        $document = $document->createdAt(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $html = $document->toHtml(new HtmlOptions(fullPage: true))->bytes();

        $canonical = $this->canonicalize($html);
        $name = ($kind === 'examples' ? 'example-' : '') . basename($file, '.php');
        $golden = __DIR__ . "/golden/$name.html";
        if (getenv('UPDATE_HTML_GOLDEN') === '1') {
            file_put_contents($golden, $canonical);
        }
        self::assertFileExists($golden);
        self::assertSame(file_get_contents($golden), $canonical);
    }

    private function canonicalize(string $html): string
    {
        set_error_handler(static function (int $no, string $msg): never {
            throw new \RuntimeException("HTML parse warning: $msg");
        });
        try {
            return \Dom\HTMLDocument::createFromString($html)->saveHtml();
        } finally {
            restore_error_handler();
        }
    }
}
