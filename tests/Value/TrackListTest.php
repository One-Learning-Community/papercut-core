<?php

declare(strict_types=1);

namespace Papercut\Tests\Value;

use Papercut\Exception\InvalidValue;
use Papercut\Value\{Auto, Fr, Length, Percent, TrackList};
use PHPUnit\Framework\TestCase;

use function Papercut\{fr, mm};

final class TrackListTest extends TestCase
{
    public function test_rethrown_error_keeps_inner_as_previous(): void
    {
        foreach (['1fr zzz', '1fr 20'] as $in) {
            try {
                TrackList::parse($in);
                self::fail('accepted');
            } catch (InvalidValue $e) {
                self::assertInstanceOf(InvalidValue::class, $e->getPrevious(), $in);
            }
        }
    }

    public function test_parse_mixed_tracks(): void
    {
        $t = TrackList::parse('3fr 25mm auto 10%');
        self::assertSame(4, $t->count());
        self::assertInstanceOf(Fr::class, $t->tracks[0]);
        self::assertInstanceOf(Length::class, $t->tracks[1]);
        self::assertInstanceOf(Auto::class, $t->tracks[2]);
        self::assertInstanceOf(Percent::class, $t->tracks[3]);
    }

    public function test_tolerates_extra_whitespace(): void
    {
        self::assertSame(2, TrackList::parse("  1fr \n\t 2fr ")->count());
    }

    public function test_split_unit_suggests_joined_value(): void
    {
        try {
            TrackList::parse('3 fr 25mm');
            self::fail('Expected InvalidValue');
        } catch (InvalidValue $e) {
            self::assertSame('column list', $e->kind);
            self::assertSame('3fr 25mm', $e->suggestion);
        }
    }

    public function test_bad_token_suggestion_is_spliced_into_whole_input(): void
    {
        try {
            TrackList::parse('1fr 25MM');
            self::fail('Expected InvalidValue');
        } catch (InvalidValue $e) {
            self::assertSame('1fr 25mm', $e->suggestion);
        }
    }

    public function test_empty_is_invalid(): void
    {
        try {
            TrackList::parse('   ');
            self::fail('Expected InvalidValue');
        } catch (InvalidValue $e) {
            self::assertSame('column list', $e->kind);
        }
    }

    public function test_auto_is_case_insensitive(): void
    {
        $t = TrackList::parse('1fr AUTO');
        self::assertInstanceOf(Fr::class, $t->tracks[0]);
        self::assertInstanceOf(Auto::class, $t->tracks[1]);
    }

    public function test_split_percent_suggestion(): void
    {
        try {
            TrackList::parse('50 % 1fr');
            self::fail('Expected InvalidValue');
        } catch (InvalidValue $e) {
            self::assertSame('50% 1fr', $e->suggestion);
        }
    }

    public function test_of(): void
    {
        self::assertSame(2, TrackList::of(fr(1), mm(20))->count());
    }
}
