<?php

declare(strict_types=1);

namespace Papercut\Tests\Style;

use Papercut\Style\StyleName;
use PHPUnit\Framework\TestCase;

final class StyleNameTest extends TestCase
{
    public function test_leads_matches_the_first_layer_only(): void
    {
        self::assertTrue(StyleName::leads('pre', 'pre'));
        self::assertTrue(StyleName::leads('pre mine', 'pre'));
        self::assertTrue(StyleName::leads('code  mine other', 'code'));
        self::assertFalse(StyleName::leads('mine pre', 'pre'));
        self::assertFalse(StyleName::leads('prefix', 'pre'));
        self::assertFalse(StyleName::leads(null, 'pre'));
    }
}
