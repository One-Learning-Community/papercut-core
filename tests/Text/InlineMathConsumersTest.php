<?php

declare(strict_types=1);

namespace Papercut\Tests\Text;

use Papercut\Decorator\{Badge, Field};
use Papercut\Markup\Markup;
use Papercut\Math\Math;
use Papercut\{Span, Text};
use Papercut\Toc\HeadingText;
use PHPUnit\Framework\TestCase;

/** Code that reads a span's text must not lose a formula, whose text is empty. */
final class InlineMathConsumersTest extends TestCase
{
    public function test_heading_text_includes_the_formulas_linear_text(): void
    {
        $t = Text::spans(Span::text('Area '), Span::math('A = π', Math::sup('r', '2')), Span::text(' of a disc'))->asHeading(2);
        self::assertSame('Area A = πr² of a disc', HeadingText::plain($t));
    }

    public function test_badge_with_math_has_no_inline_form(): void
    {
        self::assertNull(Badge::make(Text::spans(Span::math('x')))->inlineSpan());
        self::assertNotNull(Badge::make(Text::spans(Span::text('x')))->inlineSpan());
    }

    public function test_field_holding_only_math_is_not_empty(): void
    {
        self::assertFalse(Field::make(Text::spans(Span::math('x')))->isEmpty());
    }

    public function test_flatten_of_a_pre_text_keeps_a_math_span(): void
    {
        $flat = Markup::flatten(Text::spans(Span::text('a'), Span::math('x'))->apply('pre'));
        self::assertNotNull($flat->spanList()[1]->math);
    }
}
