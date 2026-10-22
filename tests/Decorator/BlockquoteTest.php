<?php

declare(strict_types=1);

namespace Papercut\Tests\Decorator;

use Papercut\{Document, Page, Text, View};
use Papercut\Decorator\{Blockquote, Decorator};
use Papercut\Style\Direction;
use PHPUnit\Framework\TestCase;

final class BlockquoteTest extends TestCase
{
    public function test_is_a_decorator(): void
    {
        self::assertInstanceOf(Decorator::class, Blockquote::make());
    }

    public function test_outer(): void
    {
        $outer = Blockquote::make('Quote')->apply('mine')->render();

        self::assertSame('blockquote mine', $outer->styleName());
        self::assertSame('blockquote', $outer->htmlElement());
        self::assertCount(1, $outer->children());
    }

    public function test_string_cite(): void
    {
        $outer = Blockquote::make('Quote')->cite('Jane')->render();

        self::assertSame('blockquote', $outer->styleName());
        $cite = $outer->children()[1];
        self::assertInstanceOf(Text::class, $cite);
        self::assertSame('blockquote.cite', $cite->styleName());
        self::assertSame("\u{2014} Jane", $cite->spanList()[0]->text);
    }

    public function test_node_cite(): void
    {
        $inner = Text::make('Jane');
        $cite = Blockquote::make('Quote')->cite($inner)->render()->children()[1];

        self::assertInstanceOf(View::class, $cite);
        self::assertSame('blockquote.cite', $cite->styleName());
        self::assertSame(Direction::Row, $cite->style()->direction);
        $dash = $cite->children()[0];
        self::assertInstanceOf(Text::class, $dash);
        self::assertSame("\u{2014} ", $dash->spanList()[0]->text);
        self::assertSame($inner, $cite->children()[1]);
    }

    public function test_immutable(): void
    {
        $plain = Blockquote::make('Quote');
        $cited = $plain->cite('X');

        self::assertNotSame($plain, $cited);
        self::assertCount(1, $plain->render()->children());
    }

    public function test_renders_in_pdf_and_html(): void
    {
        $doc = Document::make(Page::a4()->body(Blockquote::make('Words')->cite('Jane')));
        $html = $doc->toHtml()->bytes();

        self::assertNotSame('', $doc->toPdf()->bytes());
        self::assertStringContainsString('<blockquote', $html);
        self::assertStringContainsString("\u{2014} Jane", $html);
    }
}
