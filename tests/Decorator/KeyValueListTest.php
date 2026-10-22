<?php

declare(strict_types=1);

namespace Papercut\Tests\Decorator;

use Papercut\{Document, Page, Table, Text, View};
use Papercut\Decorator\{Decorator, KeyValueList, KeyValuePair};
use Papercut\Style\{ComputedStyle, StyleResolver, Theme};
use Papercut\Value\TrackList;
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

final class KeyValueListTest extends TestCase
{
    public function test_is_a_decorator(): void
    {
        self::assertInstanceOf(Decorator::class, KeyValueList::make());
    }

    public function test_renders_two_column_table(): void
    {
        $table = KeyValueList::make()->row('Respondent', 'Jane')->row('Submitted', Text::make('Today'))->render();

        self::assertInstanceOf(Table::class, $table);
        self::assertSame('key-value', $table->styleName());
        self::assertEquals(TrackList::parse('auto 1fr'), $table->trackList());
        self::assertCount(2, $table->bodyRows());
        [$label, $value] = $table->bodyRows()[0]->cells();
        self::assertSame('key-value.label', $label->styleName());
        self::assertSame('key-value.value', $value->styleName());
    }

    public function test_add_appends_pairs(): void
    {
        $list = KeyValueList::make(KeyValuePair::of('A', '1'));
        $more = $list->add(KeyValuePair::of('B', '2'), KeyValuePair::of('C', '3'));

        self::assertCount(1, $list->pairs());
        self::assertCount(3, $more->pairs());
    }

    public function test_label_width(): void
    {
        $table = KeyValueList::make(KeyValuePair::of('A', 'B'))->labelWidth(pt(120))->render();

        self::assertInstanceOf(Table::class, $table);
        self::assertEquals(TrackList::parse('120pt 1fr'), $table->trackList());
    }

    public function test_pairs_accessor_and_immutability(): void
    {
        $plain = KeyValueList::make();
        $more = $plain->row('A', 'B');

        self::assertCount(0, $plain->pairs());
        self::assertCount(1, $more->pairs());
        self::assertInstanceOf(Text::class, $more->pairs()[0]->label);
    }

    public function test_empty_renders_styled_view(): void
    {
        $out = KeyValueList::make()->apply('mine')->render();

        self::assertInstanceOf(View::class, $out);
        self::assertSame('key-value mine', $out->styleName());
        self::assertCount(0, $out->children());
    }

    public function test_outer_style_precedence(): void
    {
        $table = KeyValueList::make()->row('A', 'B')->apply('mine')->padding(pt(3))->render();

        self::assertInstanceOf(Table::class, $table);
        self::assertSame('key-value mine', $table->styleName());
        self::assertEquals(pt(3), $table->style()->padding?->top);
    }

    public function test_label_cell_padding_comes_from_named_style(): void
    {
        $theme = Theme::default();
        $table = KeyValueList::make()->row('A', 'B')->render();
        self::assertInstanceOf(Table::class, $table);
        $label = $table->bodyRows()[0]->cells()[0];

        $computed = (new StyleResolver($theme))->resolve($label, ComputedStyle::root($theme));
        self::assertSame(2.0, $computed->padding->top->points);
        self::assertSame(12.0, $computed->padding->right->points);
        self::assertSame(2.0, $computed->padding->bottom->points);
        self::assertSame(0.0, $computed->padding->left->points);
    }

    public function test_renders_in_pdf_and_html(): void
    {
        $doc = Document::make(Page::a4()->body(KeyValueList::make()->row('Respondent', 'Jane')));

        self::assertNotSame('', $doc->toPdf()->bytes());
        self::assertStringContainsString('<table', $doc->toHtml()->bytes());
    }
}
