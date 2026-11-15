<?php

declare(strict_types=1);

namespace Papercut\Tests\Hardening;

use Papercut\Exception\InvalidValue;
use Papercut\{Table, Text};
use Papercut\Tests\Support\FailsOnDiagnostics;
use PHPUnit\Framework\TestCase;

final class LeftoversTest extends TestCase
{
    use FailsOnDiagnostics;

    public function test_mixed_table_columns_arguments_throw_invalid_value_naming_the_method(): void
    {
        try {
            Table::make()->columns('1fr 1fr', '2fr');
            self::fail('no exception');
        } catch (InvalidValue $e) {
            self::assertStringContainsString('Table::columns()', $e->getMessage());
            self::assertStringContainsString('one string or TrackList, or typed tracks', $e->getMessage());
        }
    }

    public function test_a_link_with_spaces_around_its_address_stays_one_span(): void
    {
        $spans = Text::markup('<a href=" https://x">one<b></b>two</a>')->spanList();
        self::assertCount(1, $spans);
        self::assertSame('https://x', $spans[0]->href);
    }
}
