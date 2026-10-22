<?php

declare(strict_types=1);

namespace Papercut\Tests\Decorator;

use InvalidArgumentException;
use Papercut\{Column, Document, Page, Text, View};
use Papercut\Decorator\{Badge, Decorator};
use Papercut\Style\{Align, ComputedStyle, StyleResolver, Theme};
use Papercut\Value\Color;
use Papercut\Tests\Support\LayoutHarness;
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

final class BadgeTest extends TestCase
{
    public function test_is_a_decorator(): void
    {
        self::assertInstanceOf(Decorator::class, Badge::make('x'));
    }

    public function test_default_tone(): void
    {
        $outer = Badge::make('Agree')->render();

        self::assertSame('badge badge.neutral', $outer->styleName());
        self::assertNull($outer->style()->alignSelf);
        $theme = Theme::default();
        self::assertSame(Align::Start, (new StyleResolver($theme))->resolve($outer, ComputedStyle::root($theme))->alignSelf);
        self::assertCount(1, $outer->children());
        self::assertInstanceOf(Text::class, $outer->children()[0]);
    }

    public function test_tone(): void
    {
        self::assertSame('badge badge.warning', Badge::make('x')->tone('warning')->render()->styleName());
        self::assertSame('badge badge.warning mine', Badge::make('x')->tone('warning')->apply('mine')->render()->styleName());
    }

    public function test_node_content(): void
    {
        $t = Text::make('Rich');
        self::assertSame($t, Badge::make($t)->render()->children()[0]);
    }

    public function test_explicit_align_self_overrides(): void
    {
        self::assertSame(Align::End, Badge::make('x')->alignSelf(Align::End)->render()->style()->alignSelf);
    }

    public function test_resolved_background(): void
    {
        $theme = Theme::default();
        $c = (new StyleResolver($theme))->resolve(Badge::make('x')->render(), ComputedStyle::root($theme));
        self::assertEquals(Color::named('slate-100'), $c->background);
    }

    public function test_empty_tone_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Badge::make('x')->tone('');
    }

    public function test_whitespace_tone_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Badge::make('x')->tone('   ');
    }

    public function test_tone_with_internal_whitespace_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Badge::make('x')->tone('a b');
    }

    public function test_immutable(): void
    {
        $a = Badge::make('x');
        $b = $a->tone('tip');
        self::assertNotSame($a, $b);
        self::assertSame('badge badge.neutral', $a->render()->styleName());
    }

    public function test_hugs_its_text_in_a_column(): void
    {
        $f = (new LayoutHarness())->layout(Column::make(Badge::make('Agree')), 300)->fragment;
        self::assertNotNull($f);
        $badge = $f->children()[0]['fragment'];

        $inner = $badge->children()[0]['fragment'];
        self::assertEqualsWithDelta(0.0, $f->children()[0]['x'], 0.01);
        self::assertEqualsWithDelta($inner->width + 12.0, $badge->width, 0.5);
        self::assertLessThan(100.0, $badge->width);
    }

    public function test_renders_in_pdf_and_html(): void
    {
        $doc = Document::make(Page::a4()->body(Badge::make('Agree')->tone('tip')));

        self::assertNotSame('', $doc->toPdf()->bytes());
        self::assertStringContainsString('Agree', $doc->toHtml()->bytes());
    }
}
