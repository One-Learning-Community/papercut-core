<?php

declare(strict_types=1);

namespace Papercut\Tests\Text;

use Papercut\Exception\InvalidValue;
use Papercut\Text\InlineMarkup;
use Papercut\Value\Color;
use PHPUnit\Framework\TestCase;

final class InlineMarkupTest extends TestCase
{
    public function test_plain_text_is_one_span(): void
    {
        $s = InlineMarkup::parse('Hello world');
        self::assertCount(1, $s);
        self::assertSame('Hello world', $s[0]->text);
    }

    public function test_nested_tags(): void
    {
        $s = InlineMarkup::parse('a <b>b <i>c</i></b> d');
        self::assertSame(['a ', 'b ', 'c', ' d'], array_map(fn ($x) => $x->text, $s));
        self::assertSame(700, $s[1]->style->fontWeight);
        self::assertSame(700, $s[2]->style->fontWeight);
        self::assertTrue($s[2]->style->italic);
        self::assertNull($s[3]->style->fontWeight);
    }

    public function test_mark_sets_a_yellow_background(): void
    {
        $s = InlineMarkup::parse('a <mark>b</mark> c');
        self::assertSame(['a ', 'b', ' c'], array_map(fn ($x) => $x->text, $s));
        self::assertNull($s[0]->style->background);
        self::assertSame((string) Color::named('yellow-200'), (string) $s[1]->style->background);
        self::assertNull($s[2]->style->background);
    }

    public function test_mark_combines_with_other_tags_and_rejects_attributes(): void
    {
        $s = InlineMarkup::parse('<mark><b>k</b></mark>');
        self::assertSame(700, $s[0]->style->fontWeight);
        self::assertSame((string) Color::named('yellow-200'), (string) $s[0]->style->background);
        $this->expectException(InvalidValue::class);
        InlineMarkup::parse('<mark x="1">k</mark>');
    }

    public function test_link_break_and_span_attrs(): void
    {
        $s = InlineMarkup::parse('<a href="https://x.test">go</a><br/><span color="red-500" font-size="14pt">r</span>');
        self::assertSame('https://x.test', $s[0]->href);
        self::assertTrue($s[1]->lineBreak);
        self::assertSame('#ef4444', (string) $s[2]->style->color);
        self::assertSame(14.0, $s[2]->style->fontSize?->points);
    }

    public function test_entities_decoded_after_tag_parsing(): void
    {
        $s = InlineMarkup::parse('&lt;b&gt; &amp; <u>x</u>');
        self::assertSame('<b> & ', $s[0]->text);
        self::assertTrue($s[1]->style->underline);
    }

    public function test_rejects_unknown_and_unbalanced(): void
    {
        foreach (['<script>x</script>', '<b>x', 'x</b>', '<b><i>x</b></i>'] as $bad) {
            try {
                InlineMarkup::parse($bad);
                self::fail("accepted $bad");
            } catch (InvalidValue) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_rejection_reasons(): void
    {
        $cases = [
            '<script>x</script>' => 'Invalid inline markup "<script>x</script>": <script> is not allowed.',
            '<b>x' => 'Invalid inline markup "<b>x": <b> is not closed.',
            'x</b>' => 'Invalid inline markup "x</b>": unexpected </b>.',
            '<b><i>x</b></i>' => 'Invalid inline markup "<b><i>x</b></i>": unexpected </b>.',
        ];
        foreach ($cases as $markup => $message) {
            try {
                InlineMarkup::parse((string) $markup);
                self::fail("accepted $markup");
            } catch (InvalidValue $e) {
                self::assertSame($message, $e->getMessage());
            }
        }
    }

    public function test_lone_less_than_is_literal_and_markup_factory_works(): void
    {
        $s = InlineMarkup::parse('a < b <3');
        self::assertSame('a < b <3', $s[0]->text);
        $t = \Papercut\Text::markup('x <b>y</b>');
        self::assertCount(2, $t->spanList());
    }

    public function test_rejection_reports_inline_markup_kind(): void
    {
        try {
            InlineMarkup::parse('<script>x</script>');
            self::fail('accepted');
        } catch (InvalidValue $e) {
            self::assertSame('inline markup', $e->kind);
        }
    }

    // --- Task 13 ---------------------------------------------------------

    public function test_link_href_scheme_is_validated_at_parse_time(): void
    {
        foreach (['<a href="javascript:alert(1)">x</a>', '<a href="docs/a.pdf">x</a>', '<a href="">x</a>', '<a href="ftp://x.test">x</a>'] as $bad) {
            try {
                InlineMarkup::parse($bad);
                self::fail("accepted $bad");
            } catch (InvalidValue $e) {
                self::assertSame(
                    sprintf('Invalid inline markup "%s": <a href> must use http, https, mailto, tel or a #fragment.', $bad),
                    $e->getMessage(),
                );
            }
        }
        foreach (['https://x.test', 'HTTP://x.test', 'mailto:a@x.test', 'Tel:+1', '#top', '  https://x.test'] as $good) {
            self::assertNotNull(InlineMarkup::parse(sprintf('<a href="%s">x</a>', $good))[0]->href);
        }
    }
}
