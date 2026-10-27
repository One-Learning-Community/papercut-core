<?php

declare(strict_types=1);

namespace Papercut\Tests\Feature;

use Papercut\Document;
use Papercut\Export\PdfOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the exact PDF bytes of v0.1. Phase 0 of the multi-format refactor must not change them.
 *
 * Record (only on unchanged v0.1 code): UPDATE_GOLDEN=1 vendor/bin/phpunit --filter GoldenBytesTest
 * A php-pdf upgrade can change the font objects alone (php-pdf 1.26: subset tags, CIDSet, checksums).
 * Re-record then, after checking that the drawing itself is unchanged: delete the changed keys and record.
 */
final class GoldenBytesTest extends TestCase
{
    private const string GOLDEN = __DIR__ . '/golden/pdf-v0.1.json';

    /** @var list<string> */
    private const array EXAMPLES = ['invoice', 'transcript', 'report'];

    /** @var list<string> visual fixtures that use features v0.1 did not have, so they have no v0.1 bytes to match */
    private const array NEWER_FIXTURES = ['markup-slide', 'decorators', 'decorators-split'];

    /** @return iterable<string, array{string}> */
    public static function cases(): iterable
    {
        $files = glob(__DIR__ . '/../Visual/fixtures/*.php') ?: [];
        sort($files);
        foreach ($files as $file) {
            if (in_array(basename($file, '.php'), self::NEWER_FIXTURES, true)) {
                continue;
            }
            $name = 'fixture:' . basename($file, '.php');
            yield $name => [$name];
        }
        foreach (self::EXAMPLES as $example) {
            yield "example:$example" => ["example:$example"];
        }
    }

    #[DataProvider('cases')]
    public function test_pdf_bytes_match_v01(string $name): void
    {
        $hash = hash('sha256', $this->bytesFor($name));
        $golden = self::golden();
        if (getenv('UPDATE_GOLDEN') === '1' && !array_key_exists($name, $golden)) {
            self::recordGolden($name, $hash);
            $golden[$name] = $hash;
        }
        self::assertArrayHasKey($name, $golden, "No golden hash for $name; this phase must not add cases");
        self::assertSame($golden[$name], $hash, "PDF bytes for $name changed; Phase 0 must keep PDF output byte-identical");
    }

    private function bytesFor(string $name): string
    {
        [$kind, $id] = explode(':', $name, 2);
        if ($kind === 'fixture') {
            $document = require __DIR__ . "/../Visual/fixtures/$id.php";
            self::assertInstanceOf(Document::class, $document);

            return $this->pdfBytes($document->createdAt(new \DateTimeImmutable('2026-01-01T00:00:00Z')));
        }

        $document = require dirname(__DIR__, 4) . "/examples/core/documents/$id.php";
        self::assertInstanceOf(Document::class, $document);

        return $this->pdfBytes($document);
    }

    /** v0.1 had no bookmarks; with them off, everything that is drawn must still match its bytes. BookmarksTest covers the outline. */
    private function pdfBytes(Document $d): string
    {
        return $d->toPdf(new PdfOptions(bookmarks: false))->bytes();
    }

    public function test_golden_keys_match_the_cases_exactly(): void
    {
        $names = array_keys(iterator_to_array(self::cases()));
        sort($names);
        $keys = array_keys(self::golden());
        sort($keys);
        self::assertSame($names, $keys, 'Golden file keys must equal the test cases exactly');
    }

    /** @return array<string, string> */
    private static function golden(): array
    {
        if (!is_file(self::GOLDEN)) {
            return [];
        }
        /** @var array<string, string> $golden */
        $golden = json_decode((string) file_get_contents(self::GOLDEN), true, flags: JSON_THROW_ON_ERROR);

        return $golden;
    }

    private static function recordGolden(string $name, string $hash): void
    {
        $golden = self::golden();
        $golden[$name] = $hash;
        ksort($golden);
        file_put_contents(self::GOLDEN, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }
}
