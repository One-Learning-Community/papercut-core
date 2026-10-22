<?php

declare(strict_types=1);

namespace Papercut\Tests\Node;

use Papercut\{Column, Component, Image, Link, Node, PageBreak, PageNumber, Row, Spacer, Span, Text, View};
use Papercut\Style\{Align, Direction, ImageFit, Justify, Style, TextAlign};
use Papercut\Value\{Color, Length};
use PHPUnit\Framework\TestCase;

use function Papercut\{mm, pt};

final class NodesTest extends TestCase
{
    public function test_modifiers_return_new_instances(): void
    {
        $a = Text::make('Hi');
        $b = $a->bold()->color('red-500')->size(pt(14));
        self::assertNotSame($a, $b);
        self::assertNull($a->style()->fontWeight);
        self::assertSame(700, $b->style()->fontWeight);
        self::assertSame('#ef4444', (string) $b->style()->color);
        self::assertSame(14.0, $b->style()->fontSize?->points);
    }

    public function test_padding_shorthand(): void
    {
        $v = View::make()->padding(pt(1), pt(2));
        self::assertSame([1.0, 2.0, 1.0, 2.0], [
            $v->style()->padding?->top->points, $v->style()->padding?->right->points,
            $v->style()->padding?->bottom->points, $v->style()->padding?->left->points,
        ]);
    }

    public function test_row_and_column_presets_and_string_children(): void
    {
        $row = Row::make('a', Text::make('b'))->gap(pt(4))->justify(Justify::SpaceBetween)->align(Align::Center);
        self::assertSame(Direction::Row, $row->style()->direction);
        self::assertSame('row', $row->debugName());
        self::assertInstanceOf(Text::class, $row->children()[0]);
        self::assertSame(Align::Center, $row->style()->alignItems);
        self::assertSame('column', Column::make()->debugName());
        self::assertSame('view', View::make()->debugName());
    }

    public function test_text_presets_and_alignment(): void
    {
        self::assertSame('h2', Text::h2('Title')->preset());
        self::assertSame('h3', Text::make('x')->withPreset('h3')->preset());
        self::assertNull(Text::make('x')->preset());
        self::assertSame(TextAlign::Right, Text::make('x')->right()->style()->textAlign);
        $t = Text::make('Hello ')->append(Span::text('world', Style::make(fontWeight: 700)));
        self::assertCount(2, $t->spanList());
    }

    public function test_apply_named_and_object_styles(): void
    {
        $v = View::make()->apply('card')->apply(Style::make(background: Color::white()));
        self::assertSame('card', $v->styleName());
        self::assertSame('#ffffff', (string) $v->style()->background);
    }

    public function test_attributes_bridge(): void
    {
        $v = View::make()->attributes(['padding' => '4pt', 'direction' => 'row']);
        self::assertSame(Direction::Row, $v->style()->direction);
        self::assertSame(4.0, $v->style()->padding?->left->points);
    }

    public function test_leaf_nodes(): void
    {
        self::assertSame(ImageFit::Cover, Image::file('x.png')->fit(ImageFit::Cover)->fitMode());
        self::assertSame(ImageFit::Contain, Image::bytes('...')->fitMode());
        self::assertSame(['kind' => 'file', 'value' => 'x.png'], Image::file('x.png')->source());
        self::assertSame(10.0, Spacer::size(pt(10))->fixedSize()?->points);
        self::assertSame(2.0, Spacer::flex(2)->style()->grow);
        self::assertSame('Page {page} of {total}', PageNumber::make('Page {page} of {total}')->format());
        self::assertSame('page-break', PageBreak::make()->debugName());
        self::assertSame('https://x.test', Link::to('https://x.test', 'Go')->uri());
    }

    public function test_components_are_accepted_as_children(): void
    {
        $c = new class implements Component {
            public function render(): Node { return Text::make('from component'); }
        };
        self::assertSame($c, Column::make($c)->children()[0]);
    }

    public function test_image_width_with_mm(): void
    {
        $w = Image::file('a.png')->width(mm(10))->style()->width;
        self::assertInstanceOf(Length::class, $w);
        self::assertEqualsWithDelta(mm(10)->points, $w->points, 1e-9);
    }

    // --- Final review ---

    public function test_every_color_parameter_accepts_a_string(): void
    {
        $v = View::make()->borderColor('slate-200');
        self::assertEquals(Color::parse('slate-200'), $v->style()->borderColor);
        $b = View::make()->border(pt(1), 'red-500');
        self::assertEquals(Color::parse('red-500'), $b->style()->borderColor);
        self::assertSame(1.0, $b->style()->borderWidth?->top->points);
        self::assertEquals(Color::parse('#fff'), Text::make('x')->background('#fff')->color('#fff')->style()->color);
    }

    public function test_styled_nodes_implement_the_styled_interface(): void
    {
        $nodes = [View::make(), Text::make('x'), Image::bytes('x'), Spacer::size(pt(1)), PageBreak::make(), PageNumber::make('{page}'),
            Link::to('https://x.test', 'x'), \Papercut\Table::make(), \Papercut\TableRow::make(), \Papercut\TableCell::make('x')];
        foreach ($nodes as $n) {
            self::assertInstanceOf(\Papercut\Styled::class, $n, $n::class);
        }
        self::assertSame('card', Text::make('x')->apply('card')->styleName());
    }
}
