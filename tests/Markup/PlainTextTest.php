<?php

declare(strict_types=1);

namespace Papercut\Tests\Markup;

use Papercut\Markup\Markup;
use Papercut\Span;
use Papercut\Text;
use Papercut\View;
use PHPUnit\Framework\TestCase;

final class PlainTextTest extends TestCase
{
    public function test_entities_and_newlines(): void
    {
        $t = Markup::text("What federal law protects the privacy of student education records?&nbsp;\n");
        self::assertInstanceOf(Text::class, $t);
        self::assertSame("What federal law protects the privacy of student education records?\u{00A0}", $t->spanList()[0]->text);
        self::assertInstanceOf(Text::class, $t);
        $t2 = Markup::text("Add a Tiles Block that is set to call in the following objects:\n- Resources owned by the group");
        self::assertInstanceOf(Text::class, $t2);
        self::assertSame([false, true, false], array_map(fn (Span $s) => $s->lineBreak, $t2->spanList()));
    }

    public function test_blank_line_makes_paragraphs(): void
    {
        $v = Markup::text("Now, let's search.\n\nAnswer the questions that follow");
        self::assertInstanceOf(View::class, $v);
        self::assertCount(2, $v->children());
        $first = $v->children()[0];
        self::assertInstanceOf(Text::class, $first);
        self::assertSame('p', $first->preset());
    }

    public function test_single_paragraph_has_no_preset(): void
    {
        $t = Markup::text("one\r\ntwo");
        self::assertInstanceOf(Text::class, $t);
        self::assertNull($t->preset());
    }

    public function test_tags_are_text_and_null_is_nothing(): void
    {
        $t = Markup::text('a <b>b</b> &amp; c');
        self::assertInstanceOf(Text::class, $t);
        self::assertSame('a <b>b</b> & c', $t->spanList()[0]->text);
        foreach ([null, '', "  \n "] as $empty) {
            $n = Markup::text($empty);
            self::assertInstanceOf(View::class, $n);
            self::assertSame([], $n->children());
            self::assertSame([], $n->notes());
        }
    }

    public function test_invalid_utf8_does_not_throw(): void
    {
        self::assertInstanceOf(Text::class, Markup::text("ok \xC3\x28 bytes"));
    }
}
