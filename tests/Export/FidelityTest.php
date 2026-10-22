<?php

declare(strict_types=1);

namespace Papercut\Tests\Export;

use Papercut\{Document, Page, Text};
use Papercut\Exception\UnsupportedFeature;
use Papercut\Export\{ExportOptions, Fidelity, FidelityLevel, FidelityReport, PdfOptions};
use PHPUnit\Framework\TestCase;

final class FidelityTest extends TestCase
{
    public function test_reports_are_collected_and_forwarded(): void
    {
        $messages = [];
        $objects = [];
        $f = new Fidelity('docx', new ExportOptions(onWarning: function (string $m, ?FidelityReport $r = null) use (&$messages, &$objects): void {
            $messages[] = $m;
            $objects[] = $r;
        }));
        $f->approximated('fr column widths', 'page > body > table[0]');
        $f->dropped('radius', 'page > row[2] > column[0]');

        self::assertCount(2, $f->reports());
        self::assertSame(FidelityLevel::Approximated, $f->reports()[0]->level);
        self::assertSame(FidelityLevel::Dropped, $f->reports()[1]->level);
        self::assertSame([
            'docx: approximated fr column widths at page > body > table[0]',
            'docx: dropped radius at page > row[2] > column[0]',
        ], $messages);
        self::assertSame($f->reports(), $objects);
        self::assertSame('docx: dropped radius at page > row[2] > column[0]', $f->reports()[1]->message());
    }

    public function test_works_without_a_warning_callback(): void
    {
        $f = new Fidelity('docx', new ExportOptions());
        $f->dropped('radius', 'p');
        self::assertCount(1, $f->reports());
    }

    public function test_strict_throws_on_dropped_only(): void
    {
        $called = 0;
        $f = new Fidelity('docx', new ExportOptions(onWarning: function () use (&$called): void {
            $called++;
        }, strict: true));
        $f->approximated('fr column widths', 'page > body > table[0]');
        self::assertSame(1, $called);
        try {
            $f->dropped('radius', 'page > body > row[2] > column[0]');
            self::fail('expected UnsupportedFeature');
        } catch (UnsupportedFeature $e) {
            self::assertSame('Strict mode: docx cannot render radius at page > body > row[2] > column[0].', $e->getMessage());
            self::assertSame('radius', $e->report->feature);
        }
        self::assertSame(1, $called);
        self::assertCount(1, $f->reports());
    }

    public function test_one_argument_warning_closure_still_receives_layout_warnings(): void
    {
        $warnings = [];
        $doc = Document::make(Page::a5()->body(Text::make('漢')));
        $doc->toPdf(new PdfOptions(onWarning: function (string $m) use (&$warnings): void {
            $warnings[] = $m;
        }));
        self::assertNotSame([], $warnings);
        self::assertStringContainsString('Missing glyphs', $warnings[0]);
    }

    public function test_one_parameter_native_callable_gets_one_argument(): void
    {
        /** @var \Closure(string, ?FidelityReport=): void $strlen */
        $strlen = \Closure::fromCallable('strlen');
        $f = new Fidelity('html', new ExportOptions(onWarning: $strlen));
        $f->approximated('page box', 'page');
        self::assertCount(1, $f->reports());
    }

    public function test_two_parameter_closure_gets_the_report(): void
    {
        $seen = [];
        $f = new Fidelity('html', new ExportOptions(onWarning: function (string $m, ?FidelityReport $r = null) use (&$seen): void {
            $seen[] = $r;
        }));
        $f->approximated('page box', 'page');
        self::assertSame($f->reports(), $seen);
    }
}
