<?php

declare(strict_types=1);

namespace Quoin\Tests\Support;

use Quoin\Image;
use Quoin\Support\AltText;
use PHPUnit\Framework\TestCase;

final class AltTextTest extends TestCase
{
    public function test_invalid_utf8_is_cleaned_instead_of_emptying_the_text(): void
    {
        self::assertSame("caf\u{FFFD}", AltText::normalize("caf\xE9"));
        self::assertSame("caf\u{FFFD} au lait", AltText::normalize("  caf\xE9 \n au   lait "));
        self::assertSame("caf\u{FFFD}", Image::bytes(self::png())->alt("caf\xE9")->altText());
    }

    private static function png(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    }
}
