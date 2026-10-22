<?php

declare(strict_types=1);

namespace Papercut\Tests\Markup;

use Papercut\Document;
use Papercut\Exception\UnsupportedFeature;
use Papercut\Export\HtmlOptions;
use Papercut\Export\PdfOptions;
use Papercut\Markup\Markup;
use Papercut\Markup\MarkupOptions;
use Papercut\Markup\StyleMode;
use Papercut\Page;
use Papercut\Tests\Support\TestImages;
use Papercut\Text;
use PHPUnit\Framework\TestCase;

/** The question-set samples from spec §11, end to end through both exporters. */
final class SamplesTest extends TestCase
{
    private const SLIDES = ['q24', 'q102', 'q105', 'q107', 'q138', 'q241', 'q836', 'q1026'];

    public function test_every_sample_exports_to_pdf_and_html_with_images_present(): void
    {
        $png = TestImages::png(200, 100);
        $images = static fn (string $src): string => $png;

        foreach ([StyleMode::Theme, StyleMode::Original] as $mode) {
            $options = new MarkupOptions(styles: $mode, images: $images);
            foreach (self::SLIDES as $key) {
                $expected = $key === 'q241'
                    ? ['approximated markup <iframe> (shown as a link) at page > body > column[0] > figure[2] > iframe[0]']
                    : [];
                $doc = $this->document(Markup::html(self::slide($key), $options));
                self::assertSame($expected, $this->pdfMessages($doc), "pdf $key {$mode->value}");
                self::assertSame($expected, $this->htmlMessages($doc), "html $key {$mode->value}");
                $html = $doc->toHtml()->bytes();
                $imageCount = in_array($key, ['q102', 'q105', 'q107', 'q138'], true) ? 1 : 0;
                self::assertSame($imageCount, substr_count($html, '<img'), "images $key {$mode->value}");
                self::assertStringNotContainsString("\u{FEFF}", $html, "$key {$mode->value}");
            }
        }
    }

    public function test_missing_images_report_and_strict_fails_only_without_alt(): void
    {
        // Every src here is longer than 100 characters, so the notes show it shortened.
        $src = '/public/images/medium/files/uploaded/editor/';
        $expected = [
            'q102' => 'dropped markup <img> "' . $src . 'VEOYWisPKd36D7B1GCAeWoMncqsrw7QDUWfomL0HlA5NCAb6/Screen …" (missing) at page > body > text[0] > figure[1] > img[0]',
            'q105' => 'dropped markup <img> "' . $src . 'HUTm7R5iBY9cnW0jTshJnN0duocZ2mEtTslYGJciWnvKR2NT/Screen …" (missing) at page > body > column[0] > figure[0] > img[0]',
            'q107' => 'approximated markup <img> "' . $src . 'THHA3cid3BXr3lFUU6mKj3JJrujAYjqEIHxD6aZr5rdzQS83/Screen …" (missing; shown as its alt text) at page > body > column[0] > figure[2] > img[0]',
            'q138' => 'approximated markup <img> "' . $src . 'GLl7CVzkfBGTqCsb0LsryjWuuEJPOiPsDFKvVqS8YE6tmCge/Screen …" (missing; shown as its alt text) at page > body > column[0] > figure[0] > a[0] > img[0]',
        ];
        foreach ($expected as $key => $message) {
            // q102 keeps one paragraph once its image is gone, so it converts to a bare Text.
            $doc = $this->document(Markup::html(self::slide($key)));
            self::assertSame([$message], $this->pdfMessages($doc), $key);
            self::assertSame([$message], $this->htmlMessages($doc), $key);
        }

        $this->document(Markup::html(self::slide('q107')))->toPdf(new PdfOptions(strict: true, cacheDir: false));
        $this->document(Markup::html(self::slide('q107')))->toHtml(new HtmlOptions(strict: true));

        $this->expectException(UnsupportedFeature::class);
        $this->document(Markup::html(self::slide('q102')))->toPdf(new PdfOptions(strict: true, cacheDir: false));
    }

    public function test_prompts_and_labels(): void
    {
        foreach (self::list('prompts') as $prompt) {
            $doc = $this->document(Markup::text($prompt));
            self::assertSame([], $this->pdfMessages($doc), $prompt);
            self::assertSame([], $this->htmlMessages($doc), $prompt);
        }
        foreach (self::list('labels') as $label) {
            $converted = Markup::html($label);
            self::assertInstanceOf(Text::class, $converted, $label);
            self::assertNull($converted->preset(), $label);
            $doc = $this->document($converted);
            self::assertSame([], $this->pdfMessages($doc), $label);
            self::assertSame([], $this->htmlMessages($doc), $label);
        }
    }

    public function test_sample_105_html_output_has_outline_markers(): void
    {
        $html = $this->document(Markup::html(self::slide('q105')))->toHtml()->bytes();
        $dom = new \DOMDocument();
        self::assertTrue(@$dom->loadHTML($html));
        $xpath = new \DOMXPath($dom);

        $levels = ['//ol' => 'decimal', '//ol/li/ol' => 'lower-alpha', '//ol/li/ol/li/ol' => 'lower-roman'];
        foreach ($levels as $query => $marker) {
            $nodes = $xpath->query($query);
            self::assertNotFalse($nodes, $query);
            $ol = $nodes->item(0);
            self::assertInstanceOf(\DOMElement::class, $ol, $query);
            self::assertStringContainsString("list-style-type:$marker", $ol->getAttribute('style'), $query);
        }
    }

    private function document(\Papercut\View|Text $body): Document
    {
        return Document::make(Page::letter()->body($body));
    }

    /** @return list<string> */
    private function pdfMessages(Document $doc): array
    {
        $messages = [];
        $doc->toPdf(new PdfOptions(onWarning: function (string $m) use (&$messages): void {
            $messages[] = $m;
        }, cacheDir: false));
        return array_map(static fn (string $m): string => substr($m, strlen('pdf: ')), $messages);
    }

    /** @return list<string> */
    private function htmlMessages(Document $doc): array
    {
        $messages = [];
        $doc->toHtml(new HtmlOptions(onWarning: function (string $m) use (&$messages): void {
            $messages[] = $m;
        }));
        // The HTML exporter always reports that it cannot reproduce the page box; that is not about the markup.
        $messages = array_filter($messages, static fn (string $m): bool => $m !== 'html: approximated page box at page');
        return array_values(array_map(static fn (string $m): string => substr($m, strlen('html: ')), $messages));
    }

    private static function slide(string $key): ?string
    {
        $slide = self::samples()[$key];
        self::assertTrue($slide === null || is_string($slide));
        return $slide;
    }

    /** @return list<string> */
    private static function list(string $key): array
    {
        $list = self::samples()[$key];
        self::assertIsArray($list);
        return array_values(array_filter($list, is_string(...)));
    }

    /** @return array<string, string|list<string>|null> */
    private static function samples(): array
    {
        return require __DIR__ . '/../fixtures/markup/samples.php';
    }
}
