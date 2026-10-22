<?php

declare(strict_types=1);

namespace Papercut\Tests\Style;

use Papercut\Exception\UnknownAttribute;
use Papercut\Decorator\DefaultStyles;
use Papercut\Style\{Style, Theme};

use function Papercut\pt;
use Papercut\Value\Color;
use PHPUnit\Framework\TestCase;

final class ThemeTest extends TestCase
{
    public function test_heading_presets(): void
    {
        $t = Theme::default();
        $sizes = [1 => 24.0, 2 => 18.0, 3 => 14.0, 4 => 12.0];
        foreach ($sizes as $level => $size) {
            $h = $t->heading($level);
            self::assertSame($size, $h->fontSize?->points);
            self::assertSame(700, $h->fontWeight);
            self::assertSame($size * 0.5, $h->margin?->bottom->points);
        }
        self::assertSame(6.0, $t->paragraph()->margin?->bottom->points);
    }

    public function test_named_styles_are_immutable_additions(): void
    {
        $t = Theme::default();
        $t2 = $t->withStyle('panel', Style::make(background: Color::white()));
        self::assertSame('#ffffff', (string) $t2->named('panel')->background);
        $this->expectException(UnknownAttribute::class);
        $t->named('panel');
    }

    public function test_unknown_style_suggestion(): void
    {
        $t = Theme::default()->withStyle('panel', Style::make());
        $this->expectExceptionMessage('Unknown style "pnael". Did you mean "panel"?');
        $t->named('pnael');
    }

    public function test_with_overrides(): void
    {
        $t = Theme::default()->with(fontSize: 11.0, fontFamily: 'Brand');
        self::assertSame(11.0, $t->fontSize);
        self::assertSame('Brand', $t->fontFamily);
        self::assertSame(10.0, Theme::default()->fontSize);
    }

    public function test_bad_heading_level(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Theme::default()->heading(5);
    }

    public function test_named_merges_space_separated_names_left_to_right(): void
    {
        $t = Theme::default()
            ->withStyle('a', Style::make(color: Color::named('red-500'), italic: true))
            ->withStyle('b', Style::make(color: Color::named('blue-500')));
        $s = $t->named('a b');
        self::assertEquals(Color::named('blue-500'), $s->color);
        self::assertTrue($s->italic);
        self::assertEquals($s, $t->named("  a\t b "));
    }

    public function test_named_falls_back_to_decorator_defaults(): void
    {
        self::assertEquals(DefaultStyles::get('callout.warning'), Theme::default()->named('callout.warning'));
        $custom = new Theme('X', 10.0, 400, Color::black(), 1.4, \Papercut\Value\Edges::zero(), 2);
        self::assertEquals(DefaultStyles::get('callout.warning'), $custom->named('callout.warning'));
    }

    public function test_theme_style_shadows_default(): void
    {
        $t = Theme::default()->withStyle('callout.warning', Style::make(background: Color::named('red-50')));
        $s = $t->named('callout callout.warning');
        self::assertEquals(Color::named('red-50'), $s->background);
        self::assertEquals(DefaultStyles::get('callout')?->padding, $s->padding);
        self::assertEquals(DefaultStyles::get('callout.tip'), $t->named('callout.tip'));
    }

    public function test_unknown_name_in_list_suggests(): void
    {
        try {
            Theme::default()->named('callout callout.warnin');
            self::fail('expected exception');
        } catch (UnknownAttribute $e) {
            self::assertSame('callout.warning', $e->suggestion);
        }
    }

    public function test_with_style_rejects_whitespace_in_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'my style'");
        Theme::default()->withStyle('my style', Style::make());
    }

    public function test_empty_name_throws(): void
    {
        $this->expectException(UnknownAttribute::class);
        Theme::default()->named('  ');
    }
}
