<?php

declare(strict_types=1);

namespace Papercut\Tests\Decorator;

use Papercut\{Document, Page, Text, View};
use Papercut\Decorator\{Card, Decorator};
use Papercut\Style\{ComputedStyle, Style, StyleResolver, Theme};
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

final class CardTest extends TestCase
{
    public function test_is_a_decorator(): void
    {
        self::assertInstanceOf(Decorator::class, Card::make());
    }

    public function test_renders_outer_body_without_title(): void
    {
        $outer = Card::make('One', Text::make('Two'))->render();

        self::assertSame('card', $outer->styleName());
        self::assertCount(1, $outer->children());
        $body = $outer->children()[0];
        self::assertInstanceOf(View::class, $body);
        self::assertSame('card.body', $body->styleName());
        self::assertCount(2, $body->children());
        self::assertInstanceOf(Text::class, $body->children()[0]);
        self::assertSame('One', $body->children()[0]->spanList()[0]->text);
    }

    public function test_title_strip(): void
    {
        $outer = Card::make('Body')->title('Respondent')->render();

        self::assertCount(2, $outer->children());
        $strip = $outer->children()[0];
        self::assertInstanceOf(View::class, $strip);
        self::assertSame('card.title', $strip->styleName());
        self::assertTrue($strip->style()->keepWithNext);
        $text = $strip->children()[0];
        self::assertInstanceOf(Text::class, $text);
        self::assertSame('Respondent', $text->spanList()[0]->text);
        $body = $outer->children()[1];
        self::assertInstanceOf(View::class, $body);
        self::assertSame('card.body', $body->styleName());
    }

    public function test_node_title_is_used_as_is(): void
    {
        $title = Text::make('Rich');
        $strip = Card::make('Body')->title($title)->render()->children()[0];

        self::assertInstanceOf(View::class, $strip);
        self::assertSame($title, $strip->children()[0]);
    }

    public function test_style_modifiers_hit_outer_box(): void
    {
        $outer = Card::make('Body')->padding(pt(12))->apply('mine')->render();

        self::assertSame('card mine', $outer->styleName());
        $theme = Theme::default()->withStyle('mine', Style::make());
        $computed = (new StyleResolver($theme))->resolve($outer, ComputedStyle::root($theme));
        self::assertSame(12.0, $computed->padding->top->points);
    }

    public function test_immutable(): void
    {
        $plain = Card::make('Body');
        $titled = $plain->title('T');

        self::assertNotSame($plain, $titled);
        self::assertCount(1, $plain->render()->children());
        self::assertCount(2, $titled->render()->children());
    }

    public function test_renders_in_pdf_and_html(): void
    {
        $doc = Document::make(Page::a4()->body(Card::make('Body text')->title('Respondent')));

        self::assertNotSame('', $doc->toPdf()->bytes());
        self::assertStringContainsString('Respondent', $doc->toHtml()->bytes());
    }
}
