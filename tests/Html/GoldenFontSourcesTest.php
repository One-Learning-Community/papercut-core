<?php

declare(strict_types=1);

namespace Papercut\Tests\Html;

use Papercut\{Document, Page, Text};
use Papercut\Export\{FontMode, HtmlOptions};
use Papercut\Html\{GoogleFonts, LocalFonts};
use Papercut\Text\FontFace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Font source output for the example documents. Refresh with: UPDATE_HTML_GOLDEN=1 vendor/bin/phpunit tests/Html/GoldenFontSourcesTest.php */
final class GoldenFontSourcesTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool, list<mixed>}> */
    public static function cases(): iterable
    {
        yield 'example-invoice-google' => ['example-invoice-google', 'invoice', true, [new GoogleFonts()]];
        yield 'example-invoice-fragment-google' => ['example-invoice-fragment-google', 'invoice', false, [new GoogleFonts()]];
        yield 'example-report-mixed' => ['example-report-mixed', 'report', true, [
            LocalFonts::link(static fn (FontFace $f): string => 'fonts/' . basename($f->path), ['Inter']),
            new GoogleFonts(host: 'https://fonts.bunny.net'),
        ]];
        yield 'mixed-fragment' => ['mixed-fragment', 'inline-mixed', false, [
            LocalFonts::link(static fn (FontFace $f): string => 'fonts/' . basename($f->path), ['Inter']),
            new GoogleFonts(host: 'https://fonts.bunny.net'),
        ]];
    }

    /** @param list<mixed> $sources */
    #[DataProvider('cases')]
    public function test_matches_golden(string $name, string $example, bool $full, array $sources): void
    {
        $document = $example === 'inline-mixed'
            ? Document::make(Page::a4()->body(Text::make('Hello Inter'), Text::make('Hello Roboto')->font('Roboto')))
            : (static fn () => require __DIR__ . "/../../../../examples/core/documents/$example.php")();
        self::assertInstanceOf(Document::class, $document);
        $document = $document->createdAt(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $html = $document->toHtml(new HtmlOptions(fullPage: $full, fontMode: FontMode::Sources, fontSources: $sources))->bytes();

        // A fragment has no document around it; give the parser one so it does not report a missing doctype.
        $canonical = $this->canonicalize($full ? $html : '<!DOCTYPE html><html><head><title>fragment</title></head><body>' . $html . '</body></html>');
        $golden = __DIR__ . "/golden/sources/$name.html";
        if (getenv('UPDATE_HTML_GOLDEN') === '1') {
            @mkdir(dirname($golden), 0777, true);
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
