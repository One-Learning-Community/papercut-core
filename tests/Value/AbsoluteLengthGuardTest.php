<?php

declare(strict_types=1);

namespace Papercut\Tests\Value;

use Papercut\{Page, Spacer, Table};
use Papercut\Decorator\KeyValueList;
use Papercut\Exception\InvalidValue;
use Papercut\Value\{Edges, Length, TrackList};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

/** em and lh only mean something in styles; everything that needs points up front rejects them. */
final class AbsoluteLengthGuardTest extends TestCase
{
    /** @return iterable<string, array{\Closure(): mixed}> */
    public static function entryPoints(): iterable
    {
        yield 'spacer size' => [static fn () => Spacer::size(Length::em(2))];
        yield 'page margin length' => [static fn () => Page::a4()->margin(Length::em(2))];
        yield 'page margin edges' => [static fn () => Page::a4()->margin(Edges::of(pt(10), Length::lh(1)))];
        yield 'header gap' => [static fn () => Page::a4()->headerGap(Length::lh(1))];
        yield 'footer gap' => [static fn () => Page::a4()->footerGap(Length::lh(1))];
        yield 'page size' => [static fn () => Page::size(Length::em(40), pt(800))];
        yield 'table columns' => [static fn () => Table::make()->columns(Length::em(5), pt(10))];
        yield 'track list' => [static fn () => TrackList::of(Length::em(5))];
        yield 'label width' => [static fn () => KeyValueList::make()->labelWidth(Length::em(5))];
    }

    #[DataProvider('entryPoints')]
    public function test_relative_length_rejected(\Closure $call): void
    {
        try {
            $call();
            self::fail('expected InvalidValue');
        } catch (InvalidValue $e) {
            self::assertStringContainsString('em and lh only work in style properties', $e->getMessage());
        }
    }

    public function test_parse_errors_keep_the_reason(): void
    {
        foreach ([static fn () => Edges::parse('1em 2pt'), static fn () => TrackList::parse('2em 1fr')] as $parse) {
            try {
                $parse();
                self::fail('expected InvalidValue');
            } catch (InvalidValue $e) {
                self::assertStringContainsString('em and lh only work in style properties', $e->getMessage());
            }
        }
    }
}
