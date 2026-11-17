<?php

declare(strict_types=1);

namespace Quoin\Tests\Validation;

use Quoin\Decorator\KeyValueList;
use Quoin\Exception\InvalidValue;
use Quoin\Export\{HtmlOptions, PdfOptions};
use Quoin\{Column, Document, Image, Link, Page, Place, QrCode, Spacer, Table, TableCell, Text, View};
use Quoin\Support\Check;
use Quoin\Value\{Edges, Length, Percent};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Quoin\{mm, pt, percent};

/** Developer values fail at the setter, naming it, the value and what is allowed (D3). */
final class SetterValidationTest extends TestCase
{
    protected function setUp(): void
    {
        set_error_handler(static function (int $no, string $msg, string $file, int $line): never {
            throw new \ErrorException($msg, 0, $no, $file, $line);
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
    }

    /** @return iterable<string, array{\Closure(): mixed, string, string}> closure, text the message must hold, and the allowed range it must state */
    public static function rejected(): iterable
    {
        yield 'opacity NAN' => [static fn () => Column::make()->opacity(NAN), 'View::opacity()', 'NAN'];
        yield 'opacity INF' => [static fn () => Column::make()->opacity(INF), 'View::opacity()', 'INF'];
        yield 'opacity -1' => [static fn () => Column::make()->opacity(-1), '"-1"', 'between 0 and 1'];
        yield 'opacity 1.5' => [static fn () => Column::make()->opacity(1.5), '"1.5"', 'between 0 and 1'];
        yield 'grow NAN' => [static fn () => Column::make()->grow(NAN), 'View::grow()', 'finite'];
        yield 'shrink INF' => [static fn () => Column::make()->shrink(INF), 'View::shrink()', 'finite'];
        yield 'grow -1' => [static fn () => Column::make()->grow(-1), '"-1"', '0 or more'];
        yield 'shrink -1' => [static fn () => Column::make()->shrink(-1), 'View::shrink()', '0 or more'];
        yield 'flex NAN' => [static fn () => Spacer::flex(NAN), 'Spacer::flex()', 'finite'];
        yield 'flex -2' => [static fn () => Spacer::flex(-2), 'Spacer::flex()', '0 or more'];
        yield 'width -100pt' => [static fn () => Column::make()->width(pt(-100)), 'View::width()', '"-100pt"'];
        yield 'width -10%' => [static fn () => Column::make()->width(percent(-10)), 'View::width()', '"-10%"'];
        yield 'height -1pt' => [static fn () => Column::make()->height(pt(-1)), 'View::height()', 'negative'];
        yield 'minWidth -1pt' => [static fn () => Column::make()->minWidth(pt(-1)), 'View::minWidth()', 'negative'];
        yield 'maxWidth -1pt' => [static fn () => Column::make()->maxWidth(pt(-1)), 'View::maxWidth()', 'negative'];
        yield 'minHeight -1pt' => [static fn () => Column::make()->minHeight(pt(-1)), 'View::minHeight()', 'negative'];
        yield 'maxHeight -1pt' => [static fn () => Column::make()->maxHeight(pt(-1)), 'View::maxHeight()', 'negative'];
        yield 'basis -1pt' => [static fn () => Column::make()->basis(pt(-1)), 'View::basis()', 'negative'];
        yield 'padding -1pt' => [static fn () => Column::make()->padding(pt(-1)), 'View::padding()', 'negative'];
        yield 'padding one side' => [static fn () => Column::make()->padding(pt(1), pt(2), pt(-3), pt(4)), '"-3pt"', 'negative'];
        yield 'padding edges' => [static fn () => Column::make()->padding(Edges::all(pt(-1))), 'View::padding()', 'negative'];
        yield 'gap -1pt' => [static fn () => Column::make()->gap(pt(-1)), 'View::gap()', 'negative'];
        yield 'border width' => [static fn () => Column::make()->border(pt(-1)), 'View::border()', 'negative'];
        yield 'borderWidth' => [static fn () => Column::make()->borderWidth(pt(-1)), 'View::borderWidth()', 'negative'];
        yield 'radius -5pt' => [static fn () => Column::make()->radius(pt(-5)), 'View::radius()', '"-5pt"'];
        yield 'negative em width' => [static fn () => Column::make()->width(Length::em(-2)), 'View::width()', '"-2em"'];
        yield 'spacer size' => [static fn () => Spacer::fixed(pt(-4)), 'Spacer::size()', 'negative'];
        yield 'track pt' => [static fn () => Table::make()->columns(pt(-10), pt(20)), 'Table::columns()', '"-10pt"'];
        yield 'track percent' => [static fn () => Table::make()->columns(percent(-10)), 'Table::columns()', '"-10%"'];
        yield 'track string' => [static fn () => Table::make()->columns('10pt -20pt'), 'column list', 'negative'];
        yield 'labelWidth' => [static fn () => KeyValueList::make()->labelWidth(pt(-5)), 'KeyValueList::labelWidth()', '"-5pt"'];
        yield 'image width 0' => [static fn () => Image::bytes('x')->width(pt(0)), 'Image::width()', 'more than 0'];
        yield 'image width -1' => [static fn () => Image::bytes('x')->width(pt(-1)), 'Image::width()', '"-1pt"'];
        yield 'image height 0%' => [static fn () => Image::bytes('x')->height(percent(0)), 'Image::height()', 'more than 0'];
        yield 'qr width 0' => [static fn () => QrCode::make('a')->width(pt(0)), 'QrCode::width()', 'more than 0'];
        yield 'qr height -1' => [static fn () => QrCode::make('a')->height(pt(-1)), 'QrCode::height()', '"-1pt"'];
        yield 'quiet zone huge' => [static fn () => QrCode::make('a')->quietZone(PHP_INT_MAX), 'QrCode::quietZone()', 'between 0 and 100'];
        yield 'quiet zone negative' => [static fn () => QrCode::make('a')->quietZone(-1), 'QrCode::quietZone()', 'between 0 and 100'];
        yield 'modules cell' => [static fn () => QrCode::fromModules([[1, 'x'], [0, 1]]), 'row 0, column 1', 'true or false'];
        yield 'modules float' => [static fn () => QrCode::fromModules([[1, 0.5], [0, 1]]), 'row 0, column 1', 'true or false'];
        yield 'link newline' => [static fn () => Link::to("https://a\nb"), 'Link::to()', 'NUL, CR or LF'];
        yield 'link NUL' => [static fn () => Link::to("https://a\0b"), 'Link::to()', 'NUL, CR or LF'];
        yield 'link scheme' => [static fn () => Link::to('javascript:x'), 'javascript:x', 'only http, https, mailto, tel and #fragment'];
        yield 'link data' => [static fn () => Link::to('data:text/html,x'), 'Link::to()', 'only http, https, mailto, tel and #fragment'];
        yield 'place em' => [static fn () => Place::at(Length::em(2), pt(0)), 'Place::at()', 'em and lh only work in style properties'];
        yield 'place width negative' => [static fn () => Place::at(pt(0), pt(0))->width(pt(-3)), 'Place::width()', 'negative'];
        yield 'place width em' => [static fn () => Place::at(pt(0), pt(0))->width('2em'), 'Place::width()', 'em and lh only'];
    }

    /** @param \Closure(): mixed $make */
    #[DataProvider('rejected')]
    public function test_bad_value_throws_at_the_setter(\Closure $make, string $names, string $states): void
    {
        try {
            $make();
            self::fail('expected InvalidValue');
        } catch (InvalidValue $e) {
            self::assertStringContainsString($names, $e->getMessage());
            self::assertStringContainsString($states, $e->getMessage());
        }
    }

    /** @return iterable<string, array{\Closure(): mixed}> */
    public static function accepted(): iterable
    {
        yield 'opacity 0' => [static fn () => Column::make(Text::make('x'))->opacity(0)];
        yield 'opacity 1' => [static fn () => Column::make(Text::make('x'))->opacity(1)];
        yield 'opacity half' => [static fn () => Column::make(Text::make('x'))->opacity(0.5)];
        yield 'padding 0' => [static fn () => Column::make(Text::make('x'))->padding(pt(0))];
        yield 'padding mixed' => [static fn () => Column::make(Text::make('x'))->padding(pt(0), mm(3), pt(2), Length::em(0.5))];
        yield 'border 0' => [static fn () => Column::make(Text::make('x'))->border(pt(0))];
        yield 'radius 0' => [static fn () => Column::make(Text::make('x'))->radius(pt(0))];
        yield 'gap 0' => [static fn () => Column::make(Text::make('a'), Text::make('b'))->gap(pt(0))];
        yield 'width 0' => [static fn () => Column::make(Text::make('x'))->width(pt(0))];
        yield 'min 0' => [static fn () => Column::make(Text::make('x'))->minWidth(pt(0))->minHeight(pt(0))];
        yield 'grow 0 shrink 0' => [static fn () => Column::make(Text::make('x'))->grow(0)->shrink(0)];
        yield 'grow big' => [static fn () => Column::make(Text::make('x'))->grow(3.5)];
        yield 'zero spacer' => [static fn () => Column::make(Text::make('a'), Spacer::fixed(pt(0)), Text::make('b'))];
        yield 'flex 0' => [static fn () => Column::make(Text::make('a'), Spacer::flex(0), Text::make('b'))];
        yield 'negative margin' => [static fn () => Column::make(Text::make('x'))->margin(pt(0), pt(0), pt(0), pt(-10))];
        yield 'negative margin all' => [static fn () => Column::make(Text::make('x'))->margin(pt(-5))];
        yield 'negative letter spacing' => [static fn () => Column::make(Text::make('x')->letterSpacing(pt(-0.5)))];
        yield 'zero track' => [static fn () => Table::make()->columns(pt(0), \Quoin\Value\Fr::of(1))->row('a', 'b')];
        yield 'zero percent track' => [static fn () => Table::make()->columns(percent(0), pt(20))->row('a', 'b')];
        yield 'quiet zone 0' => [static fn () => QrCode::make('a')->quietZone(0)];
        yield 'quiet zone 100' => [static fn () => QrCode::make('a')->quietZone(100)];
        yield 'modules bools' => [static fn () => QrCode::fromModules([[true, false], [false, true]])];
        yield 'link fragment' => [static fn () => Link::to('#top', Text::make('x'))];
        yield 'link mailto' => [static fn () => Link::to('MAILTO:a@b.test', Text::make('x'))];
        yield 'place in points and percent' => [static fn () => Place::at(pt(0), percent(50), Text::make('x'))->width(percent(50))];
        yield 'key value label width' => [static fn () => KeyValueList::make()->labelWidth(pt(0))];
    }

    /** @param \Closure(): mixed $make */
    #[DataProvider('accepted')]
    public function test_legitimate_value_builds_and_exports(\Closure $make): void
    {
        $node = $make();
        $doc = match (true) {
            $node instanceof Place => Document::make(Page::a4()->body(Column::make(Text::make('x')))->overlay($node)),
            $node instanceof KeyValueList => Document::make(Page::a4()->body($node->row('a', 'b'))),
            default => Document::make(Page::a4()->body($node)),
        };
        self::assertStringStartsWith('%PDF', $doc->toPdf(new PdfOptions(cacheDir: false))->bytes());
        $html = $doc->toHtml(new HtmlOptions())->bytes();
        self::assertStringContainsString('class="quoin"', $html);
        self::assertMatchesRegularExpression('/<(div|table|svg|img|p|span)[ >]/', $html);
    }

    /** Every kind the export allow-list accepts is still accepted when the link is built. */
    public function test_link_kinds_the_export_accepts_are_accepted(): void
    {
        foreach (['https://a.test/x?y=1#z', 'HTTP://A.TEST', 'mailto:a@b.test', 'tel:+15551234', '#section-2', '#'] as $uri) {
            self::assertSame($uri, Link::to($uri, 'x')->uri());
            $doc = Document::make(Page::a4()->body(Link::to($uri, Text::make('x'))));
            self::assertStringStartsWith('%PDF', $doc->toPdf(new PdfOptions(cacheDir: false))->bytes());
        }
    }

    public function test_nan_is_printed_without_a_float_to_string_warning(): void
    {
        foreach ([NAN, INF, -INF] as $bad) {
            try {
                Check::finite($bad, 'X::y()');
                self::fail('expected InvalidValue');
            } catch (InvalidValue $e) {
                self::assertStringContainsString(InvalidValue::number($bad), $e->getMessage());
            }
        }
    }

    public function test_check_returns_what_it_was_given(): void
    {
        self::assertSame(2.5, Check::range(2.5, 0, 5, 'X::y()'));
        self::assertSame(0.0, Check::nonNegative(0.0, 'X::y()'));
        self::assertSame(1.0, Check::positive(1.0, 'X::y()'));
        self::assertSame(3, Check::intRange(3, 0, 5, 'X::y()'));
        $l = pt(3);
        self::assertSame($l, Check::size($l, 'X::y()'));
        $e = Edges::all(pt(0));
        self::assertSame($e, Check::edges($e, 'X::y()'));
        $this->expectException(InvalidValue::class);
        Check::intRange(6, 0, 5, 'X::y()');
    }

    public function test_huge_colspan_is_clamped_with_one_note_and_does_not_hang(): void
    {
        $doc = Document::make(Page::a4()->body(Table::make()->row(TableCell::make('x')->colspan(99999999999))));
        $messages = [];
        $started = microtime(true);
        $doc->toPdf(new PdfOptions(onWarning: function (string $m) use (&$messages): void {
            $messages[] = $m;
        }, cacheDir: false));
        self::assertLessThan(10.0, microtime(true) - $started);
        self::assertCount(1, $messages, implode("\n", $messages));
        self::assertStringContainsString('colspan runs past column 1000; clamped', $messages[0]);

        $htmlNotes = [];
        $html = $doc->toHtml(new HtmlOptions(onWarning: function (string $m) use (&$htmlNotes): void {
            $htmlNotes[] = $m;
        }))->bytes();
        $clamps = array_values(array_filter($htmlNotes, static fn (string $m): bool => str_contains($m, 'colspan runs past column 1000')));
        self::assertCount(1, $clamps, implode("\n", $htmlNotes));
        self::assertStringContainsString('approximated', $clamps[0]);
        self::assertSame(1000, preg_match_all('/<col[ >]/', $html));
        self::assertStringContainsString('colspan="1000"', $html);
        self::assertStringNotContainsString('99999999999', $html);
    }

    public function test_colspan_within_the_table_is_untouched(): void
    {
        $doc = Document::make(Page::a4()->body(Table::make()->columns('1fr 1fr 1fr')->row(TableCell::make('x')->colspan(3))));
        $messages = [];
        $doc->toPdf(new PdfOptions(onWarning: function (string $m) use (&$messages): void {
            $messages[] = $m;
        }, cacheDir: false));
        self::assertSame([], $messages);
        self::assertSame(3, preg_match_all('/<col[ >]/', $doc->toHtml(new HtmlOptions())->bytes()));
    }

    public function test_untrusted_markup_with_hostile_link_or_negative_width_still_never_throws(): void
    {
        $html = '<a href="https://a.test/&#10;x"><img src="data:image/png;base64,AA" width="10"></a>'
            . '<table><tr><td style="width:-10pt">a</td><td width="-5">b</td></tr></table>';
        $doc = Document::make(Page::a4()->body(\Quoin\Markup\Markup::html($html)));
        self::assertStringStartsWith('%PDF', $doc->toPdf(new PdfOptions(cacheDir: false))->bytes());
    }
}
