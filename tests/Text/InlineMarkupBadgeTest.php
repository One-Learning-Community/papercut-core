<?php

declare(strict_types=1);

namespace Papercut\Tests\Text;

use Papercut\Exception\InvalidValue;
use Papercut\{Span, Text};
use Papercut\Tests\Support\LayoutHarness;
use Papercut\Text\InlineMarkup;
use PHPUnit\Framework\TestCase;

final class InlineMarkupBadgeTest extends TestCase
{
    public function test_plain_badge(): void
    {
        self::assertEquals([Span::badge('201')], InlineMarkup::parse('<badge>201</badge>'));
    }

    public function test_type_and_variant(): void
    {
        self::assertEquals(
            [Span::text('Returns '), Span::badge('v2', 'tip', 'outline'), Span::text(' ok')],
            InlineMarkup::parse('Returns <badge type="tip" variant="outline">v2</badge> ok'),
        );
    }

    public function test_entities_decode(): void
    {
        self::assertSame('a & b', InlineMarkup::parse('<badge>a &amp; b</badge>')[0]->text);
    }

    public function test_nested_markup_is_rejected(): void
    {
        $this->expectException(InvalidValue::class);
        $this->expectExceptionMessage('plain text');
        InlineMarkup::parse('<badge><b>x</b></badge>');
    }

    public function test_unknown_attribute_is_rejected(): void
    {
        $this->expectException(InvalidValue::class);
        $this->expectExceptionMessage('not allowed');
        InlineMarkup::parse('<badge colour="x">x</badge>');
    }

    public function test_badge_in_link_carries_href(): void
    {
        $spans = InlineMarkup::parse('<a href="https://example.com"><badge>docs</badge></a>');
        self::assertCount(1, $spans);
        self::assertSame('https://example.com', $spans[0]->href);
        self::assertSame(Span::badge('docs')->styleName, $spans[0]->styleName);
        self::assertSame('docs', $spans[0]->text);
    }

    public function test_badge_after_link_has_no_href(): void
    {
        $spans = InlineMarkup::parse('<a href="https://example.com">x</a><badge>y</badge>');
        self::assertNull($spans[1]->href);
    }

    public function test_bad_tone_is_rejected(): void
    {
        $this->expectException(InvalidValue::class);
        $this->expectExceptionMessage('single word');
        InlineMarkup::parse('<badge type="two words">x</badge>');
    }

    public function test_empty_label_is_rejected(): void
    {
        $this->expectException(InvalidValue::class);
        $this->expectExceptionMessage('needs a label');
        InlineMarkup::parse('<badge></badge>');
    }

    public function test_span_badge_rejects_empty_and_blank_labels(): void
    {
        foreach (['', '  '] as $label) {
            try {
                Span::badge($label);
                self::fail('expected InvalidArgumentException');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('needs a label', $e->getMessage());
            }
        }
    }

    public function test_unknown_type_names_the_theme_style(): void
    {
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessage('badge.zzz');
        (new LayoutHarness())->layout(Text::markup('<badge type="zzz">x</badge>'), 300);
    }
}
