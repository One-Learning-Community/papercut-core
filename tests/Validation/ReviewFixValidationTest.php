<?php

declare(strict_types=1);

namespace Quoin\Tests\Validation;

use Quoin\Exception\{InvalidStructure, InvalidValue};
use Quoin\Export\{DocxOptions, HtmlOptions, PdfOptions};
use Quoin\Markup\Markup;
use Quoin\Style\Style;
use Quoin\Tests\Support\FailsOnDiagnostics;
use Quoin\{Column, Document, Image, Link, Page, QrCode, Span, Table, Text};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Quoin\pt;

/** Final fix wave: setters the first pass missed (I2, M1, M5) and the message and attribute leftovers. */
final class ReviewFixValidationTest extends TestCase
{
    use FailsOnDiagnostics;

    /** @return iterable<string, array{\Closure(): mixed, string, string}> */
    public static function rejected(): iterable
    {
        yield 'lineHeight NAN' => [static fn () => Text::make('x')->lineHeight(NAN), 'Text::lineHeight()', 'finite'];
        yield 'lineHeight INF' => [static fn () => Text::make('x')->lineHeight(INF), 'Text::lineHeight()', 'finite'];
        yield 'lineHeight 0' => [static fn () => Text::make('x')->lineHeight(0), 'Text::lineHeight()', 'more than 0'];
        yield 'lineHeight negative' => [static fn () => Text::make('x')->lineHeight(-2), 'Text::lineHeight()', 'more than 0'];
        yield 'size 0' => [static fn () => Text::make('x')->size(pt(0)), 'Text::size()', 'more than 0'];
        yield 'size negative' => [static fn () => Text::make('x')->size(pt(-5)), 'Text::size()', '"-5pt"'];
        yield 'minLines negative' => [static fn () => Text::make('x')->minLines(-5), 'Text::minLines()', 'between 0'];
        yield 'quiet zone via intRange' => [static fn () => QrCode::make('a')->quietZone(101), 'QrCode::quietZone()', 'between 0 and 100'];
        yield 'opacity NAN states range' => [static fn () => Column::make()->opacity(NAN), 'View::opacity()', 'finite number between 0 and 1'];
        yield 'modules empty names method' => [static fn () => QrCode::fromModules([]), 'QrCode::fromModules()', 'empty'];
        yield 'modules ragged names method' => [static fn () => QrCode::fromModules([[true, false], [true]]), 'QrCode::fromModules()', 'each row needs 2'];
        yield 'modules keyed names method' => [static fn () => QrCode::fromModules(['a' => [true]]), 'QrCode::fromModules()', 'list'];
        yield 'track names method' => [static fn () => Table::make()->columns(pt(-10), pt(20)), 'Table::columns()', '"-10pt"'];
        yield 'track string names method' => [static fn () => Table::make()->columns('10pt -20pt'), 'Table::columns()', 'negative'];
        yield 'span newline' => [static fn () => new Span('x', href: "http://a\nb"), 'Span', 'NUL, CR or LF'];
        yield 'span scheme' => [static fn () => new Span('x', href: 'javascript:x'), 'Span', 'only http, https, mailto, tel and #fragment'];
        yield 'link interior newline' => [static fn () => Link::to("https://a\nb"), 'Link::to()', 'NUL, CR or LF'];
    }

    /** @param \Closure(): mixed $make */
    #[DataProvider('rejected')]
    public function test_bad_value_throws_naming_the_call(\Closure $make, string $names, string $states): void
    {
        try {
            $make();
            self::fail('expected InvalidValue');
        } catch (InvalidValue $e) {
            self::assertStringContainsString($names, $e->getMessage());
            self::assertStringContainsString($states, $e->getMessage());
        }
    }

    public function test_good_values_are_still_accepted(): void
    {
        $t = Text::make('x')->lineHeight(1.2)->size(pt(12))->minLines(0);
        $doc = Document::make(Page::a4()->body($t));
        self::assertStringStartsWith('%PDF', $doc->toPdf(new PdfOptions(cacheDir: false))->bytes());
        self::assertStringContainsString('line-height', $doc->toHtml(new HtmlOptions())->bytes());
        new Span('x', href: '  https://a.test/x  ');
        new Span('x', href: '#top');
    }

    public function test_leading_and_trailing_whitespace_in_a_link_is_trimmed_like_markup_does(): void
    {
        self::assertSame('https://a.test', Link::to("https://a.test\n", 'x')->uri());
        self::assertSame('https://a.test', Link::to("\n https://a.test", 'x')->uri());
        $s = new Span('x', href: "https://a.test\n");
        self::assertSame('https://a.test', $s->href);
    }

    public function test_an_image_or_qr_size_of_zero_through_attributes_throws(): void
    {
        foreach ([Image::bytes('x'), QrCode::make('a')] as $node) {
            foreach (['width', 'height'] as $attr) {
                try {
                    $node->attributes([$attr => '0pt']);
                    self::fail('expected InvalidValue');
                } catch (InvalidValue $e) {
                    self::assertStringContainsString($attr, $e->getMessage());
                    self::assertStringContainsString('more than 0', $e->getMessage());
                }
            }
        }
        // A view may still be zero wide.
        $width = Column::make(Text::make('x'))->attributes(['width' => '0pt'])->style()->width;
        self::assertInstanceOf(\Quoin\Value\Length::class, $width);
        self::assertSame(0.0, $width->points);
    }

    public function test_style_attributes_reject_non_finite_line_height_and_font_size(): void
    {
        foreach (['line-height' => NAN, 'font-size' => 'NAN', 'min-lines' => -1] as $attr => $value) {
            try {
                Style::fromAttributes([$attr => $value]);
                self::fail("expected InvalidValue for $attr");
            } catch (InvalidValue $e) {
                self::assertStringContainsString($attr, $e->getMessage());
            }
        }
    }

    public function test_error_correction_on_a_grid_is_a_quoin_exception(): void
    {
        $qr = QrCode::fromModules([[true]]);
        foreach ([static fn () => $qr->errorCorrection('H')] as $call) {
            try {
                $call();
                self::fail('expected InvalidStructure');
            } catch (InvalidStructure $e) {
                self::assertStringContainsString('QrCode::errorCorrection()', $e->getMessage());
                self::assertStringContainsString('QrCode::make()', $e->getMessage());
            }
        }
    }

    public function test_markup_link_note_escapes_control_characters(): void
    {
        $notes = [];
        $doc = Document::make(Page::a4()->body(Markup::html("<a href=\"javascript:a&#10;b\">hi</a>")));
        $doc->toPdf(new PdfOptions(onWarning: static function (string $m) use (&$notes): void {
            $notes[] = $m;
        }, cacheDir: false));
        self::assertCount(1, $notes);
        self::assertStringNotContainsString("\n", $notes[0]);
        self::assertStringContainsString('javascript:a\\nb', $notes[0]);
    }
}
