<?php

declare(strict_types=1);

namespace Papercut\Tests\Layout;

use Papercut\{Column, Document, Page, Row, Table, Text, View};
use Papercut\Decorator\Field;
use Papercut\Layout\{Fragment, LayoutResult, Status};
use Papercut\Tests\Support\LayoutHarness;
use PHPUnit\Framework\TestCase;

use function Papercut\{mm, percent, pt};

/** min-height and max-height on boxes that size to their content. */
final class MinMaxHeightTest extends TestCase
{
    public function test_view_grows_to_min_height(): void
    {
        $r = (new LayoutHarness())->layout(View::make(Text::make('One line'))->border(pt(1))->minHeight(pt(80)), 200);
        self::assertEqualsWithDelta(80.0, self::frag($r)->height, 0.001);
    }

    public function test_column_and_row_grow_to_min_height(): void
    {
        $h = new LayoutHarness();
        self::assertEqualsWithDelta(80.0, self::frag($h->layout(Column::make(Text::make('x'))->minHeight(pt(80)), 200))->height, 0.001);
        self::assertEqualsWithDelta(80.0, self::frag($h->layout(Row::make(Text::make('x'), Text::make('y'))->minHeight(pt(80)), 200))->height, 0.001);
    }

    public function test_min_height_inside_a_column_and_a_row(): void
    {
        $h = new LayoutHarness();
        $box = View::make(Text::make('x'))->border(pt(1))->minHeight(pt(80));

        $col = self::frag($h->layout(Column::make($box, Text::make('after')), 200));
        self::assertEqualsWithDelta(80.0, $col->children()[0]['fragment']->height, 0.001);
        self::assertEqualsWithDelta(80.0, $col->children()[1]['y'], 0.001, 'the next sibling sits below the full min-height');

        $row = self::frag($h->layout(Row::make($box, Text::make('beside')), 200));
        self::assertEqualsWithDelta(80.0, $row->height, 0.001);
    }

    public function test_content_taller_than_min_height_keeps_its_height(): void
    {
        $r = (new LayoutHarness())->layout(View::make(...array_map(fn (int $i) => Text::make("line $i"), range(1, 10)))->minHeight(pt(20)), 200);
        self::assertEqualsWithDelta(140.0, self::frag($r)->height, 0.001);
    }

    public function test_text_honours_min_max_and_explicit_height(): void
    {
        $h = new LayoutHarness();
        self::assertEqualsWithDelta(80.0, self::frag($h->layout(Text::make('x')->border(pt(1))->minHeight(pt(80)), 200))->height, 0.001);
        self::assertEqualsWithDelta(80.0, self::frag($h->layout(Text::make('x')->height(pt(80)), 200))->height, 0.001);
        self::assertEqualsWithDelta(20.0, self::frag($h->layout(Text::markup("a<br>b<br>c<br>d")->maxHeight(pt(20)), 200))->height, 0.001);
    }

    public function test_max_height_caps_a_content_sized_box(): void
    {
        $r = (new LayoutHarness())->layout(View::make(Text::make('a'), Text::make('b'), Text::make('c'), Text::make('d'))->maxHeight(pt(20)), 200);
        self::assertEqualsWithDelta(20.0, self::frag($r)->height, 0.001);
    }

    public function test_empty_field_with_lines_is_that_tall(): void
    {
        $r = (new LayoutHarness())->layout(Field::make()->lines(4), 200);
        // lines(4) = four 14pt lines inside the field's 5pt padding and 1pt border: 56 + 10 + 2.
        self::assertEqualsWithDelta(68.0, self::frag($r)->height, 0.001);
    }

    public function test_min_height_that_does_not_fit_moves_to_the_next_page(): void
    {
        $h = new LayoutHarness();
        $box = View::make(Text::make('x'))->minHeight(pt(80));

        $notFresh = $h->layout($box, 200, 50.0, null, false);
        self::assertSame(Status::DoesNotFit, $notFresh->status);

        $fresh = $h->layout($box, 200, 50.0, null, true);
        self::assertEqualsWithDelta(50.0, self::frag($fresh)->height, 0.001, 'on a fresh page it is capped to the space');
    }

    public function test_max_height_box_does_not_split_near_a_page_break(): void
    {
        $h = new LayoutHarness();
        $box = View::make(...array_map(fn (int $i) => Text::make("line $i"), range(1, 10)))->maxHeight(pt(20));

        // 50pt left on a page with content: the capped 20pt box fits, so it is placed whole.
        $placed = $h->layout($box, 200, 50.0, null, false);
        self::assertSame(Status::Complete, $placed->status);
        self::assertEqualsWithDelta(20.0, self::frag($placed)->height, 0.001);

        // 10pt left: it moves to the next page rather than splitting.
        self::assertSame(Status::DoesNotFit, $h->layout($box, 200, 10.0, null, false)->status);
    }

    public function test_text_with_height_or_max_height_does_not_split(): void
    {
        $h = new LayoutHarness();
        $lines = Text::markup(implode('<br>', range(1, 10)));
        foreach ([$lines->height(pt(20)), $lines->maxHeight(pt(20))] as $text) {
            $r = $h->layout($text, 200, 50.0, null, false);
            self::assertSame(Status::Complete, $r->status);
            self::assertEqualsWithDelta(20.0, self::frag($r)->height, 0.001);
            self::assertSame(Status::DoesNotFit, $h->layout($text, 200, 10.0, null, false)->status);
        }
    }

    public function test_row_and_text_with_min_height_move_when_it_does_not_fit(): void
    {
        $h = new LayoutHarness();
        self::assertSame(Status::DoesNotFit, $h->layout(Row::make(Text::make('x'))->minHeight(pt(80)), 200, 50.0, null, false)->status);
        self::assertSame(Status::DoesNotFit, $h->layout(Text::make('x')->minHeight(pt(80)), 200, 50.0, null, false)->status);
        self::assertEqualsWithDelta(50.0, self::frag($h->layout(Text::make('x')->minHeight(pt(80)), 200, 50.0, null, true))->height, 0.001);
    }

    public function test_min_height_wins_over_a_smaller_max_height(): void
    {
        $r = (new LayoutHarness())->layout(View::make(Text::make('x'))->minHeight(pt(60))->maxHeight(pt(30)), 200);
        self::assertEqualsWithDelta(60.0, self::frag($r)->height, 0.001);
    }

    public function test_percent_min_height_without_a_definite_height_is_ignored(): void
    {
        $r = (new LayoutHarness())->layout(View::make(Text::make('x'))->minHeight(percent(50)), 200);
        self::assertEqualsWithDelta(14.0, self::frag($r)->height, 0.001);
    }

    public function test_documents_with_tall_min_heights_terminate(): void
    {
        $filler = array_map(fn (int $i) => Text::make("filler $i"), range(1, 30));
        $nested = View::make(View::make(Text::make('inner'))->minHeight(pt(5000)))->minHeight(pt(3000));
        $table = Table::make()->columns('1fr')->row(View::make(Text::make('cell'))->minHeight(pt(5000)));

        foreach ([$nested, $table, View::make(Text::make('x'))->minHeight(pt(5000))->keepTogether()] as $tall) {
            $doc = Document::make(Page::a6()->margin(mm(10))->body(Column::make(...$filler, ...[$tall, Text::make('after')])));
            self::assertStringStartsWith('%PDF', $doc->toPdf()->bytes());
        }
    }

    public function test_cap_taller_than_an_empty_page_splits_instead_of_losing_content(): void
    {
        $h = new LayoutHarness();
        $lines = array_map(fn (int $i) => Text::make("line $i"), range(1, 100));
        foreach ([View::make(...$lines)->maxHeight(pt(5000)), Column::make(...$lines)->maxHeight(pt(5000)), Text::markup(implode('<br>', range(1, 100)))->height(pt(5000)), View::make(...$lines)->minHeight(pt(5000))->maxHeight(pt(100)), Text::markup(implode('<br>', range(1, 100)))->minHeight(pt(5000))->maxHeight(pt(100))] as $box) {
            $r = $h->layout($box, 200, 300.0, null, true);
            self::assertSame(Status::Partial, $r->status, 'splits across pages');
            self::assertLessThanOrEqual(300.0, self::frag($r)->height);
        }
    }

    public function test_table_cell_grows_to_min_height(): void
    {
        $r = (new LayoutHarness())->layout(Table::make()->columns('1fr')->row(View::make(Text::make('cell'))->minHeight(pt(60))), 200);
        self::assertGreaterThanOrEqual(60.0, self::frag($r)->height);
    }

    private static function frag(LayoutResult $r): Fragment
    {
        self::assertNotNull($r->fragment);
        return $r->fragment;
    }
}
