<?php

declare(strict_types=1);

namespace Papercut\Tests\Support;

use Papercut\Support\Suggest;
use PHPUnit\Framework\TestCase;

final class SuggestTest extends TestCase
{
    public function test_closest_within_distance(): void
    {
        self::assertSame('padding', Suggest::closest('paddng', ['margin', 'padding']));
        self::assertNull(Suggest::closest('zzzzzz', ['margin', 'padding']));
        self::assertSame('abcd', Suggest::closest('abxx', new \ArrayIterator(['abcd', 'wxyz']), 2));
        self::assertNull(Suggest::closest('abxx', ['abcd'], 1));
    }

    public function test_ties_resolve_to_the_alphabetically_first_candidate(): void
    {
        self::assertSame('bat', Suggest::closest('cat', ['rat', 'bat']));
    }
}
