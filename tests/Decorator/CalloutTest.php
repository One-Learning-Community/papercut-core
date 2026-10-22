<?php

declare(strict_types=1);

namespace Papercut\Tests\Decorator;

use InvalidArgumentException;
use Papercut\{Document, Page, Text, View};
use Papercut\Decorator\{Callout, Decorator};
use Papercut\Exception\UnknownAttribute;
use Papercut\Style\{Style, Theme};
use Papercut\Tests\Support\LayoutHarness;
use PHPUnit\Framework\TestCase;

final class CalloutTest extends TestCase
{
    public function test_is_a_decorator(): void
    {
        self::assertInstanceOf(Decorator::class, Callout::make());
    }

    public function test_default_tone_note(): void
    {
        $outer = Callout::make('Body')->render();

        self::assertSame('callout callout.note', $outer->styleName());
        self::assertSame('aside', $outer->htmlElement());
        self::assertSame('note', Callout::make()->toneName());
        self::assertCount(1, $outer->children());
    }

    public function test_tone_rejects_internal_whitespace(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Callout::make()->tone('very bad');
    }

    public function test_tone_and_title(): void
    {
        $outer = Callout::make('Body')->tone('warning')->title('Careful')->render();

        self::assertSame('callout callout.warning', $outer->styleName());
        self::assertCount(2, $outer->children());
        $title = $outer->children()[0];
        self::assertInstanceOf(Text::class, $title);
        self::assertSame('callout.title callout.title.warning', $title->styleName());
        self::assertTrue($title->style()->keepWithNext);
        self::assertSame('Careful', $title->spanList()[0]->text);
    }

    public function test_node_title_is_wrapped(): void
    {
        $inner = Text::make('Rich');
        $title = Callout::make('Body')->title($inner)->render()->children()[0];

        self::assertInstanceOf(View::class, $title);
        self::assertSame('callout.title callout.title.note', $title->styleName());
        self::assertTrue($title->style()->keepWithNext);
        self::assertSame($inner, $title->children()[0]);
    }

    public function test_user_style_name_appended(): void
    {
        self::assertSame('callout callout.tip mine', Callout::make()->tone('tip')->apply('mine')->render()->styleName());
    }

    public function test_empty_tone_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Callout::make()->tone('  ');
    }

    public function test_custom_tone_needs_theme_styles(): void
    {
        $callout = Callout::make('Body')->tone('success')->title('Yay');
        try {
            (new LayoutHarness())->layout($callout, 200);
            self::fail('Expected UnknownAttribute');
        } catch (UnknownAttribute $e) {
            self::assertStringContainsString('callout.success', $e->getMessage());
        }

        $theme = Theme::default()
            ->withStyle('callout.success', Style::make())
            ->withStyle('callout.title.success', Style::make());
        $result = (new LayoutHarness($theme))->layout($callout, 200);
        self::assertNotNull($result->fragment);
    }

    public function test_immutable(): void
    {
        $plain = Callout::make('Body');
        $tipped = $plain->tone('tip');

        self::assertNotSame($plain, $tipped);
        self::assertSame('note', $plain->toneName());
    }

    public function test_renders_in_pdf_and_html(): void
    {
        $doc = Document::make(Page::a4()->body(Callout::make('Body text')->tone('danger')->title('Heads up')));

        self::assertNotSame('', $doc->toPdf()->bytes());
        self::assertStringContainsString('<aside', $doc->toHtml()->bytes());
    }
}
