<?php

declare(strict_types=1);

namespace Papercut\Tests\Export;

use Papercut\{Document, Page, Svg, Text};
use Papercut\Export\{ExportOptions, Exporter, Output, PdfOptions};
use PHPUnit\Framework\TestCase;

/**
 * An export runs with PHP's cycle collector off (on a long document it ran
 * repeatedly and found nothing to free) and collects once at the end. The
 * caller's setting is restored either way.
 */
final class GarbageCollectionTest extends TestCase
{
    protected function tearDown(): void
    {
        gc_enable();
    }

    public function test_an_export_leaves_the_collector_on_when_it_was_on(): void
    {
        gc_enable();
        self::document()->toPdf();
        self::assertTrue(gc_enabled());
    }

    public function test_an_export_leaves_the_collector_off_when_it_was_off(): void
    {
        gc_disable();
        self::document()->toPdf();
        self::assertFalse(gc_enabled());
    }

    public function test_the_collector_is_off_during_the_export(): void
    {
        $exporter = new class implements Exporter {
            public ?bool $seen = null;

            public function export(Document $doc, ExportOptions $options): Output
            {
                $this->seen = gc_enabled();
                return new Output('', 'text/plain', 'txt');
            }

            public function defaultOptions(): ExportOptions
            {
                return new PdfOptions();
            }
        };
        gc_enable();
        self::document()->export($exporter);
        self::assertFalse($exporter->seen);
        self::assertTrue(gc_enabled());
    }

    public function test_a_failed_export_turns_the_collector_back_on(): void
    {
        $exporter = new class implements Exporter {
            public function export(Document $doc, ExportOptions $options): Output
            {
                throw new \RuntimeException('export failed');
            }

            public function defaultOptions(): ExportOptions
            {
                return new PdfOptions();
            }
        };
        gc_enable();
        try {
            self::document()->export($exporter);
            self::fail('The export should have thrown.');
        } catch (\RuntimeException $e) {
            self::assertSame('export failed', $e->getMessage());
        }
        self::assertTrue(gc_enabled());
    }

    public function test_an_export_frees_the_cycles_it_leaves(): void
    {
        $exporter = new class implements Exporter {
            public function export(Document $doc, ExportOptions $options): Output
            {
                $cycle = new \stdClass();
                $cycle->self = $cycle;
                return new Output('', 'text/plain', 'txt');
            }

            public function defaultOptions(): ExportOptions
            {
                return new PdfOptions();
            }
        };
        gc_enable();
        gc_collect_cycles();
        self::document()->export($exporter);
        self::assertSame(0, gc_collect_cycles(), 'the export collected its own cycle on the way out');
    }

    public function test_a_nested_export_leaves_the_collector_off_for_the_outer_one(): void
    {
        $exporter = new class implements Exporter {
            public ?bool $afterInner = null;

            public function export(Document $doc, ExportOptions $options): Output
            {
                $doc->toHtml();
                $this->afterInner = gc_enabled();
                return new Output('', 'text/plain', 'txt');
            }

            public function defaultOptions(): ExportOptions
            {
                return new PdfOptions();
            }
        };
        gc_enable();
        self::document()->export($exporter);
        self::assertFalse($exporter->afterInner);
        self::assertTrue(gc_enabled());
    }

    public function test_an_export_creates_no_reference_cycles(): void
    {
        // Cycles would keep the export's caches alive until a collection finds them.
        $doc = Document::make(Page::a4()->body(
            Text::make('Text through the itemizer'),
            Svg::fromString('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 20"><text x="0" y="15">SVG text</text></svg>'),
        ));
        gc_enable();
        gc_collect_cycles();
        $before = gc_status()['collected'];
        $doc->toPdf();
        $doc->toHtml();
        self::assertSame(0, gc_status()['collected'] - $before);
    }

    private static function document(): Document
    {
        return Document::make(Page::a4()->body(Text::make('Hello')));
    }
}
