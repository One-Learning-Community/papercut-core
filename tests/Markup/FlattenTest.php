<?php

declare(strict_types=1);

namespace Papercut\Tests\Markup;

use Papercut\Export\FidelityLevel;
use Papercut\Image;
use Papercut\ListItem;
use Papercut\ListNode;
use Papercut\Markup\Markup;
use Papercut\Markup\Note;
use Papercut\Span;
use Papercut\Text;
use Papercut\Style\Style;
use Papercut\Value\Color;
use Papercut\View;
use PHPUnit\Framework\TestCase;

final class FlattenTest extends TestCase
{
    public function test_two_paragraphs_join_with_a_break(): void
    {
        $flat = Markup::flatten(Markup::text("first\n\nsecond"));
        self::assertSame(['first', '', 'second'], array_map(fn (Span $s) => $s->text, $flat->spanList()));
        self::assertTrue($flat->spanList()[1]->lineBreak);
        self::assertCount(1, $flat->notes());
        self::assertSame(FidelityLevel::Approximated, $flat->notes()[0]->level);
        self::assertSame('markup block content (flattened into a line)', $flat->notes()[0]->feature);
    }

    public function test_text_is_returned_unchanged(): void
    {
        $t = Text::make('x');
        self::assertSame($t, Markup::flatten($t));
    }

    public function test_a_pre_text_moves_its_name_onto_its_spans_and_keeps_its_own_style(): void
    {
        $pre = Text::make('a  b')->apply('pre')->apply(Style::make(background: Color::parse('#00ff00')));
        $flat = Markup::flatten($pre);

        self::assertNull($flat->styleName());
        self::assertSame('pre', $flat->spanList()[0]->styleName);
        self::assertSame('#00ff00', (string) $flat->style()->background);
    }

    public function test_a_text_with_another_style_name_is_returned_unchanged(): void
    {
        $t = Text::make('x')->apply('quote');
        $flat = Markup::flatten($t);

        self::assertSame($t, $flat);
        self::assertNull($flat->spanList()[0]->styleName);
    }

    public function test_image_is_dropped_with_a_note(): void
    {
        $view = View::make(Text::make('kept'), Image::bytes('x'));
        $flat = Markup::flatten($view);
        self::assertSame('kept', $flat->spanList()[0]->text);
        self::assertCount(1, $flat->notes());
        self::assertSame(FidelityLevel::Dropped, $flat->notes()[0]->level);
        self::assertSame('markup <img> (removed when flattened into a line)', $flat->notes()[0]->feature);
    }

    public function test_list_items_contribute_text_and_notes_are_kept(): void
    {
        $note = new Note(FidelityLevel::Approximated, 'earlier');
        $view = View::make(ListNode::unordered('a', 'b'))->withNotes($note);
        $flat = Markup::flatten($view);
        self::assertSame(['a', '', 'b'], array_map(fn (Span $s) => $s->text, $flat->spanList()));
        self::assertSame('earlier', $flat->notes()[0]->feature);
        self::assertCount(2, $flat->notes());
    }

    public function test_flatten_keeps_table_text(): void
    {
        $flat = Markup::flatten(Markup::html('<table><tr><td>a</td><td>b</td></tr><tr><td>c</td></tr></table>'));
        self::assertSame(['a', ' ', 'b', '', 'c'], array_map(fn (Span $s) => $s->text, $flat->spanList()));
        self::assertTrue($flat->spanList()[3]->lineBreak);
        self::assertCount(1, $flat->notes());
        self::assertSame(FidelityLevel::Approximated, $flat->notes()[0]->level);
        self::assertSame('markup <table> (flattened into a line)', $flat->notes()[0]->feature);
    }

    public function test_flatten_turns_hr_into_a_break(): void
    {
        $flat = Markup::flatten(Markup::html('<p>a</p><hr><p>b</p>'));
        self::assertSame(['a', '', 'b'], array_map(fn (Span $s) => $s->text, $flat->spanList()));
        self::assertTrue($flat->spanList()[1]->lineBreak);
        foreach ($flat->notes() as $note) {
            self::assertNotSame(FidelityLevel::Dropped, $note->level);
        }
    }
}
