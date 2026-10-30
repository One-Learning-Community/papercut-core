<?php

declare(strict_types=1);

namespace Papercut\Tests\Code;

use Papercut\Code\CodeColors;
use Papercut\Value\Color;
use PHPUnit\Framework\TestCase;

final class CodeColorsTest extends TestCase
{
    public function test_light_background_takes_the_light_palette(): void
    {
        $c = CodeColors::for(Color::hex('#f6f8fa'));
        self::assertSame(['#94a3b8', '#e2e8f0', '#f1f5f9', '#cbd5e1'], array_map('strval', [$c->label, $c->border, $c->bar, $c->rule]));
    }

    public function test_dark_background_takes_the_dark_palette(): void
    {
        $c = CodeColors::for(Color::hex('#24292e'));
        self::assertSame(['#64748b', '#334155', '#1e293b', '#475569'], array_map('strval', [$c->label, $c->border, $c->bar, $c->rule]));
    }

    public function test_yiq_threshold_is_128(): void
    {
        self::assertTrue(CodeColors::isLight(Color::rgb(128, 128, 128)));
        self::assertFalse(CodeColors::isLight(Color::rgb(127, 128, 128)));
    }
}
