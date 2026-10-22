<?php

declare(strict_types=1);

namespace Papercut\Tests\Export;

use Papercut\{Document, Page, Span, Text, View};
use Papercut\Exception\UnsupportedFeature;
use Papercut\Export\{FidelityLevel, HtmlOptions, PdfOptions};
use Papercut\Markup\Note;
use PHPUnit\Framework\TestCase;

final class NoteReporterTest extends TestCase
{
    /** @param \ArrayObject<int, string> $messages */
    private function collector(\ArrayObject $messages): \Closure
    {
        return function (string $m) use ($messages): void {
            $messages[] = $m;
        };
    }

    public function test_pdf_and_html_report_notes_with_the_node_path(): void
    {
        $text = Text::make('x')->withNotes(new Note(FidelityLevel::Approximated, 'markup <iframe> (shown as a link)', 'figure[0] > iframe[0]'));
        $doc = Document::make(Page::a4()->body($text));

        /** @var \ArrayObject<int, string> $pdfMessages */
        $pdfMessages = new \ArrayObject();
        $doc->toPdf(new PdfOptions(onWarning: $this->collector($pdfMessages), cacheDir: false));
        /** @var \ArrayObject<int, string> $htmlMessages */
        $htmlMessages = new \ArrayObject();
        $doc->toHtml(new HtmlOptions(onWarning: $this->collector($htmlMessages)));

        self::assertSame(['pdf: approximated markup <iframe> (shown as a link) at page > body > text[0] > figure[0] > iframe[0]'], $pdfMessages->getArrayCopy());
        // The HTML exporter also reports its own page-box approximation; only the note is asserted here.
        self::assertSame(['html: approximated markup <iframe> (shown as a link) at page > body > text[0] > figure[0] > iframe[0]'], array_values(array_filter($htmlMessages->getArrayCopy(), static fn (string $m): bool => str_contains($m, 'markup'))));
    }

    public function test_strict_throws_on_dropped_only(): void
    {
        $doc = Document::make(Page::a4()->body(
            View::make(Text::make('ok')->withNotes(new Note(FidelityLevel::Approximated, 'f')))
                ->withNotes(new Note(FidelityLevel::Dropped, 'markup <img> "a.png" (missing)')),
        ));
        $this->expectException(UnsupportedFeature::class);
        $this->expectExceptionMessage('Strict mode: pdf cannot render markup <img> "a.png" (missing) at page > body > column[0].');
        $doc->toPdf(new PdfOptions(strict: true, cacheDir: false));
    }

    public function test_each_export_reports_once_even_when_the_node_splits_across_pages(): void
    {
        $lines = array_map(fn (int $i) => Text::make("line $i"), range(1, 200));
        $view = View::make(...$lines)->withNotes(new Note(FidelityLevel::Approximated, 'f'));
        $doc = Document::make(Page::a6()->body($view));

        /** @var \ArrayObject<int, string> $messages */
        $messages = new \ArrayObject();
        $options = new PdfOptions(onWarning: $this->collector($messages), cacheDir: false);
        $doc->toPdf($options);
        self::assertCount(1, $messages);
        $doc->toPdf($options);
        self::assertCount(2, $messages);
    }

    public function test_append_text_merges_spans_and_notes(): void
    {
        $label = Text::make('Agree')->withNotes(new Note(FidelityLevel::Approximated, 'f'));
        $line = Text::make('× ')->append($label);
        self::assertSame(['× ', 'Agree'], array_map(fn (Span $s) => $s->text, $line->spanList()));
        self::assertCount(1, $line->notes());
    }
}
