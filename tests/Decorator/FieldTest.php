<?php

declare(strict_types=1);

namespace Papercut\Tests\Decorator;

use InvalidArgumentException;
use Papercut\{Image, Span, Text, View};
use Papercut\Decorator\{Decorator, Field};
use Papercut\Markup\Markup;
use Papercut\Value\Length;
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

final class FieldTest extends TestCase
{
    public function test_is_a_decorator(): void
    {
        self::assertInstanceOf(Decorator::class, Field::make());
    }

    public function test_no_label_box_is_outer(): void
    {
        $box = Field::make('Ada')->apply('mine')->padding(pt(2))->render();

        self::assertSame('field mine', $box->styleName());
        self::assertSame(2.0, $box->style()->padding?->top->points);
        self::assertCount(1, $box->children());
    }

    public function test_labelled_field_style_goes_on_outer_column_not_box(): void
    {
        $outer = Field::make('x')->label('L')->padding(pt(9))->apply('mine')->render();

        self::assertSame(9.0, $outer->style()->padding?->top->points);
        self::assertStringContainsString('mine', (string) $outer->styleName());
        $box = $outer->children()[1];
        self::assertInstanceOf(View::class, $box);
        self::assertSame('field', $box->styleName());
        self::assertNull($box->style()->padding);
    }

    public function test_blank_label_is_unset(): void
    {
        $box = Field::make('x')->label('  ')->render();

        self::assertSame('field', $box->styleName());
        self::assertCount(1, $box->children());
    }

    public function test_label_wraps_box_in_column(): void
    {
        $outer = Field::make('Ada')->label('Name')->apply('mine')->render();

        self::assertSame('mine', $outer->styleName());
        self::assertCount(2, $outer->children());
        $label = $outer->children()[0];
        self::assertInstanceOf(Text::class, $label);
        self::assertSame('field.label', $label->styleName());
        self::assertTrue($label->style()->keepWithNext);
        $box = $outer->children()[1];
        self::assertInstanceOf(View::class, $box);
        self::assertSame('field', $box->styleName());
    }

    public function test_label_without_user_name_is_unnamed(): void
    {
        self::assertNull(Field::make('Ada')->label('Name')->render()->styleName());
    }

    public function test_node_label_is_wrapped(): void
    {
        $inner = Text::make('Rich');
        $label = Field::make('Ada')->label($inner)->render()->children()[0];

        self::assertInstanceOf(View::class, $label);
        self::assertSame('field.label', $label->styleName());
        self::assertTrue($label->style()->keepWithNext);
        self::assertSame($inner, $label->children()[0]);
    }

    public function test_emptiness(): void
    {
        self::assertTrue(Field::make()->isEmpty());
        self::assertTrue(Field::make('')->isEmpty());
        self::assertTrue(Field::make("  \n")->isEmpty());
        self::assertTrue(Field::make("\u{00A0}")->isEmpty());
        self::assertTrue(Field::make(Markup::text(''))->isEmpty());
        self::assertTrue(Field::make(Text::spans())->isEmpty());
        self::assertTrue(Field::make(Text::spans(Span::break(), Span::text(' ')))->isEmpty());
        self::assertFalse(Field::make('x')->isEmpty());
        self::assertFalse(Field::make(View::make(Text::make('x')))->isEmpty());
        self::assertFalse(Field::make(Image::bytes('x'))->isEmpty());
        self::assertFalse(Field::make('', 'x')->isEmpty());
    }

    public function test_view_of_empty_text_counts_as_empty(): void
    {
        // Markup::text('') returns an empty View, which is indistinguishable from View::make().
        self::assertTrue(Field::make(View::make())->isEmpty());
        self::assertTrue(Field::make(View::make(Text::make(' ')))->isEmpty());
    }

    public function test_placeholder_when_empty(): void
    {
        $box = Field::make()->placeholder('No response')->render();

        self::assertCount(1, $box->children());
        $p = $box->children()[0];
        self::assertInstanceOf(Text::class, $p);
        self::assertSame('field.placeholder', $p->styleName());
        self::assertSame('No response', $p->spanList()[0]->text);
    }

    public function test_no_placeholder_when_empty_leaves_box_bare(): void
    {
        self::assertCount(0, Field::make()->render()->children());
    }

    public function test_placeholder_not_shown_when_filled(): void
    {
        $box = Field::make('Ada')->placeholder('No response')->render();

        self::assertCount(1, $box->children());
        $child = $box->children()[0];
        self::assertInstanceOf(Text::class, $child);
        self::assertSame('Ada', $child->spanList()[0]->text);
    }

    public function test_lines_sets_min_height(): void
    {
        $inner = Field::make()->lines(3)->render()->children()[0];
        self::assertInstanceOf(View::class, $inner);
        self::assertSame('3lh', (string) $inner->style()->minHeight, 'n lines of the field\'s own text, inside its padding');
    }

    public function test_lines_applies_to_inner_box_with_label(): void
    {
        $box = Field::make()->label('L')->lines(2)->render()->children()[1];
        self::assertInstanceOf(View::class, $box);
        $inner = $box->children()[0];
        self::assertInstanceOf(View::class, $inner);
        self::assertSame('2lh', (string) $inner->style()->minHeight);
    }

    public function test_lines_below_one_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Field::make()->lines(0);
    }

    public function test_immutable(): void
    {
        $plain = Field::make();
        self::assertNotSame($plain, $plain->label('x'));
        self::assertNotSame($plain, $plain->lines(2));
        self::assertNotSame($plain, $plain->placeholder('p'));
        self::assertCount(0, $plain->render()->children());
    }
}
