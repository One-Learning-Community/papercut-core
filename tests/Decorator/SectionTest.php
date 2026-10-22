<?php

declare(strict_types=1);

namespace Papercut\Tests\Decorator;

use Papercut\{Column, Document, Page, Text, View};
use Papercut\Decorator\{Decorator, Section};
use Papercut\Paint\{Painter, TextRun};
use Papercut\Value\Edges;
use Papercut\Style\{ComputedStyle, Direction, Style, StyleResolver, Theme};
use Papercut\Tests\Support\FakeBackend;
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

final class SectionTest extends TestCase
{
    private static function view(\Papercut\Node|\Papercut\Component $n): View
    {
        self::assertInstanceOf(View::class, $n);
        return $n;
    }

    private static function text(\Papercut\Node|\Papercut\Component $n): Text
    {
        self::assertInstanceOf(Text::class, $n);
        return $n;
    }

    public function test_is_a_decorator(): void
    {
        self::assertInstanceOf(Decorator::class, Section::make());
    }

    public function test_without_title_or_number_has_only_body(): void
    {
        $outer = Section::make('Body')->render();

        self::assertSame('section', $outer->styleName());
        self::assertSame('section', $outer->htmlElement());
        self::assertCount(1, $outer->children());
        self::assertSame('section.body', self::view($outer->children()[0])->styleName());
    }

    public function test_heading_with_number_and_title(): void
    {
        $outer = Section::make('Body')->title('Intro')->number(3)->rule()->render();

        self::assertCount(2, $outer->children());
        $heading = $outer->children()[0];
        self::assertInstanceOf(View::class, $heading);
        self::assertSame('section.heading section.rule', $heading->styleName());
        self::assertTrue($heading->style()->keepWithNext);
        [$number, $title] = $heading->children();
        self::assertInstanceOf(Text::class, $number);
        self::assertSame('section.number', $number->styleName());
        self::assertSame('3', $number->spanList()[0]->text);
        self::assertInstanceOf(Text::class, $title);
        self::assertSame('section.title', $title->styleName());
        self::assertSame(1.0, $title->style()->grow);
        self::assertSame('Intro', $title->spanList()[0]->text);
    }

    public function test_heading_is_always_a_row(): void
    {
        $heading = self::view(Section::make()->title('T')->number(1)->render()->children()[0]);

        self::assertSame(Direction::Row, $heading->style()->direction);
    }

    public function test_empty_number_and_title_are_unset(): void
    {
        $outer = Section::make('Body')->number('')->title('')->render();
        self::assertCount(1, $outer->children());
        self::assertSame('section.body', self::view($outer->children()[0])->styleName());

        $heading = self::view(Section::make()->number('')->title('T')->render()->children()[0]);
        self::assertCount(1, $heading->children());
        $heading = self::view(Section::make()->number(2)->title(' ')->render()->children()[0]);
        self::assertCount(1, $heading->children());
    }

    public function test_heading_without_rule(): void
    {
        $heading = self::view(Section::make()->title('T')->render()->children()[0]);

        self::assertSame('section.heading', $heading->styleName());
    }

    public function test_int_and_string_number_render_the_same(): void
    {
        $a = self::view(Section::make()->number(3)->render()->children()[0])->children()[0];
        $b = self::view(Section::make()->number('3')->render()->children()[0])->children()[0];

        self::assertSame(self::text($a)->spanList()[0]->text, self::text($b)->spanList()[0]->text);
        self::assertSame('3', self::text($b)->spanList()[0]->text);
    }

    public function test_number_without_title(): void
    {
        $heading = self::view(Section::make('Body')->number('A')->render()->children()[0]);

        self::assertCount(1, $heading->children());
        self::assertSame('section.number', self::text($heading->children()[0])->styleName());
    }

    public function test_node_title_is_wrapped(): void
    {
        $inner = Text::make('Rich');
        $title = self::view(Section::make()->title($inner)->render()->children()[0])->children()[0];

        self::assertInstanceOf(View::class, $title);
        self::assertSame('section.title', $title->styleName());
        self::assertSame(1.0, $title->style()->grow);
        self::assertSame($inner, $title->children()[0]);
    }

    public function test_keep_together_hits_outer_view(): void
    {
        $outer = Section::make('Body')->keepTogether()->render();

        self::assertTrue($outer->style()->keepTogether);
    }

    public function test_style_precedence(): void
    {
        $theme = Theme::default()->withStyle('mine', Style::make(gap: pt(20), padding: Edges::all(pt(9))));
        $resolver = new StyleResolver($theme);

        $named = $resolver->resolve(Section::make()->apply('mine')->render(), ComputedStyle::root($theme));
        self::assertSame(20.0, $named->gap);

        $explicit = $resolver->resolve(Section::make()->apply('mine')->padding(pt(3))->render(), ComputedStyle::root($theme));
        self::assertSame(3.0, $explicit->padding->top->points);
    }

    public function test_heading_moves_with_first_body_line_at_page_break(): void
    {
        $page = Page::size(pt(200), pt(100))->margin(pt(10))->headerGap(pt(0))->footerGap(pt(0));
        $filler = array_map(static fn (int $i): Text => Text::make("F$i"), range(1, 4));
        $doc = Document::make($page->body(
            Column::make(...[
                ...$filler,
                Section::make(Text::make('FirstBody'), Text::make('SecondBody'))->title('Heading'),
            ]),
        ));

        $b = new FakeBackend();
        $ctx = new \Papercut\Layout\LayoutContext(\Papercut\Text\FontRegistry::withDefaults($b), Theme::default(), $b, \Papercut\OverflowMode::Throw, static function (string $w): void {});
        $pages = Painter::paint((new \Papercut\Layout\Paginator(new \Papercut\Layout\LayoutEngine($ctx), $ctx))->paginate($doc));

        $where = [];
        foreach ($pages as $i => $p) {
            foreach ($p->ops as $op) {
                if ($op instanceof TextRun) {
                    $where[$op->text] = $i;
                }
            }
        }
        self::assertSame($where['Heading'], $where['FirstBody']);
        self::assertGreaterThan(0, $where['Heading']);
    }

    public function test_immutable(): void
    {
        $plain = Section::make('Body');
        $titled = $plain->title('T');

        self::assertNotSame($plain, $titled);
        self::assertCount(1, $plain->render()->children());
        self::assertCount(2, $titled->render()->children());
    }

    public function test_renders_in_pdf_and_html(): void
    {
        $doc = Document::make(Page::a4()->body(Section::make('Body')->title('Heading')->number(1)->rule()));

        self::assertNotSame('', $doc->toPdf()->bytes());
        self::assertStringContainsString('<section', $doc->toHtml()->bytes());
    }
}
