<?php

declare(strict_types=1);

namespace Papercut\Tests\Exception;

use Papercut\Exception\UnknownAttribute;
use PHPUnit\Framework\TestCase;

final class UnknownAttributeTest extends TestCase
{
    public function test_plain_message(): void
    {
        self::assertSame('Unknown attribute "foo".', UnknownAttribute::for('foo')->getMessage());
    }

    public function test_message_with_suggestion(): void
    {
        self::assertSame(
            'Unknown attribute "paddng". Did you mean "padding"?',
            UnknownAttribute::for('paddng', 'padding')->getMessage(),
        );
    }

    public function test_message_with_context_and_suggestion(): void
    {
        $base = UnknownAttribute::for('paddng', 'padding');
        $e = $base->within('on <x-doc::column>');
        self::assertSame(
            'Unknown attribute "paddng" on <x-doc::column>. Did you mean "padding"?',
            $e->getMessage(),
        );
        self::assertSame($base, $e->getPrevious());
        self::assertSame(['on <x-doc::column>'], $e->locations);
    }

    public function test_repeated_within_appends_left_to_right(): void
    {
        $e = UnknownAttribute::for('x')->within('on <a>')->within('in <b>');
        self::assertSame('Unknown attribute "x" on <a> in <b>.', $e->getMessage());
        self::assertSame(['on <a>', 'in <b>'], $e->locations);
        self::assertNotNull($e->getPrevious());
    }

    public function test_context_without_suggestion_and_custom_noun(): void
    {
        self::assertSame(
            'Unknown style "x" in theme.',
            UnknownAttribute::for('x', null, 'style')->within('in theme')->getMessage(),
        );
    }
}
