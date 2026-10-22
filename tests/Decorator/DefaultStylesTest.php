<?php

declare(strict_types=1);

namespace Papercut\Tests\Decorator;

use Papercut\Decorator\DefaultStyles;
use Papercut\Style\Style;
use Papercut\Value\{Color, Edges};
use Papercut\Value\Length;
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

final class DefaultStylesTest extends TestCase
{
    public function test_every_name_resolves(): void
    {
        self::assertNotSame([], DefaultStyles::names());
        foreach (DefaultStyles::names() as $name) {
            self::assertInstanceOf(Style::class, DefaultStyles::get($name), $name);
        }
    }

    public function test_unknown_is_null(): void
    {
        self::assertNull(DefaultStyles::get('nope'));
    }

    public function test_card(): void
    {
        $s = DefaultStyles::get('card');
        self::assertEquals(Edges::all(pt(1)), $s?->borderWidth);
        self::assertEquals(Color::named('slate-300'), $s?->borderColor);
        self::assertEquals(pt(4), $s?->radius);
    }

    public function test_tones_and_badges(): void
    {
        $w = DefaultStyles::get('callout.warning');
        self::assertEquals(Color::named('amber-500'), $w?->borderColor);
        self::assertEquals(Color::named('amber-50'), $w?->background);
        self::assertEquals(Color::named('red-800'), DefaultStyles::get('callout.title.danger')?->color);
        $b = DefaultStyles::get('badge.tip');
        self::assertEquals(Color::named('emerald-100'), $b?->background);
        self::assertEquals(Color::named('emerald-800'), $b?->color);
    }

    public function test_choice_marker(): void
    {
        $m = DefaultStyles::get('choice.marker');
        self::assertSame('0.75em', (string) $m?->width);
        self::assertSame('0.75em', (string) $m?->height);
        self::assertNull($m?->margin, 'the one-line slot centres the marker; no hand-tuned offset');
    }
}
