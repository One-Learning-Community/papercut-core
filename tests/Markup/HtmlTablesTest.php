<?php

declare(strict_types=1);

namespace Papercut\Tests\Markup;

use Papercut\Decorator\DefaultStyles;
use Papercut\Document;
use Papercut\Export\FidelityLevel;
use Papercut\Export\PdfOptions;
use Papercut\Image;
use Papercut\Layout\TableGrid;
use Papercut\ListItem;
use Papercut\ListNode;
use Papercut\Markup\Markup;
use Papercut\Markup\MarkupOptions;
use Papercut\Markup\Note;
use Papercut\Page;
use Papercut\Table;
use Papercut\TableCell;
use Papercut\TableRow;
use Papercut\Tests\Support\TestImages;
use Papercut\Text;
use Papercut\Value\Auto;
use Papercut\Value\Color;
use Papercut\Value\Length;
use Papercut\Value\Percent;
use Papercut\Value\Unit;
use Papercut\View;
use PHPUnit\Framework\TestCase;

final class HtmlTablesTest extends TestCase
{
    public function test_table_becomes_a_table_with_named_styles(): void
    {
        $table = self::table('<table><tr><td>a</td><td>b</td></tr></table>');

        self::assertSame('markup.table', $table->styleName());
        self::assertTrue($table->fillsWidth());
        self::assertSame([Auto::instance(), Auto::instance()], self::tracks($table));
        $cells = $table->bodyRows()[0]->cells();
        self::assertSame(['markup.td', 'markup.td'], array_map(fn (TableCell $c) => $c->styleName(), $cells));
        self::assertSame([['a'], ['b']], array_map(fn (TableCell $c) => self::cellTexts($c), $cells));
        foreach ($cells as $cell) {
            self::assertNull($cell->children()[0] instanceof Text ? $cell->children()[0]->preset() : 'not text');
        }
    }

    public function test_thead_rows_repeat_and_tfoot_rows_go_last(): void
    {
        $table = self::table('<table><thead><tr><th>H</th></tr></thead><tfoot><tr><td>F</td></tr></tfoot><tbody><tr><td>1</td></tr></tbody></table>');

        self::assertSame([['H']], self::rowTexts($table->headerRows()));
        self::assertSame([['1'], ['F']], self::rowTexts($table->bodyRows()));
        self::assertTrue($table->headerRows()[0]->cells()[0]->header());
        self::assertSame('markup.th', $table->headerRows()[0]->cells()[0]->styleName());
    }

    public function test_leading_all_th_rows_are_the_header_without_a_thead(): void
    {
        $table = self::table('<table><tr><th>A</th><th>B</th></tr><tr><th>C</th><th>D</th></tr><tr><td>1</td><td>2</td></tr></table>');

        self::assertSame([['A', 'B'], ['C', 'D']], self::rowTexts($table->headerRows()));
        self::assertSame([['1', '2']], self::rowTexts($table->bodyRows()));
    }

    public function test_all_th_table_has_no_repeated_header(): void
    {
        $table = self::table('<table><tr><th>A</th><th>B</th></tr><tr><th>C</th><th>D</th></tr></table>');

        self::assertSame([], $table->headerRows());
        self::assertCount(2, $table->bodyRows());
        foreach ($table->bodyRows() as $row) {
            foreach ($row->cells() as $cell) {
                self::assertTrue($cell->header());
            }
        }
    }

    public function test_header_rows_never_cut_a_rowspan(): void
    {
        $v = self::view('<table><tr><th rowspan="2">H</th><th>K</th></tr><tr><td>1</td></tr><tr><td>2</td><td>3</td></tr></table>');
        $table = $v->children()[0];
        self::assertInstanceOf(Table::class, $table);

        self::assertSame([], $v->notes());
        self::assertSame([], $table->headerRows());
        self::assertCount(3, $table->bodyRows());
        $grid = TableGrid::of($table);
        self::assertSame([], [...$grid->clampedRowspans, ...$grid->clampedColspans]);
        self::assertSame(1, $grid->placements[1][0]->col);
    }

    public function test_caption_is_a_paragraph_above_the_table(): void
    {
        $v = Markup::html('<table><caption>Week 1</caption><tr><td>a</td></tr></table>');

        self::assertInstanceOf(View::class, $v);
        self::assertCount(2, $v->children());
        self::assertInstanceOf(Text::class, $v->children()[0]);
        self::assertSame('Week 1', self::plain($v->children()[0]));
        self::assertSame('p', $v->children()[0]->preset());
        self::assertInstanceOf(Table::class, $v->children()[1]);
    }

    public function test_spans_follow_html_integer_rules(): void
    {
        $table = self::table('<table><tr><td colspan="2">a</td><td colspan="0">b</td><td colspan="-1">c</td><td rowspan="0">d</td></tr><tr><td>e</td></tr><tr><td>f</td></tr></table>');
        $cells = $table->bodyRows()[0]->cells();
        self::assertSame([2, 1, 1, 1], array_map(fn (TableCell $c) => $c->span(), $cells));
        self::assertSame(3, $cells[3]->rowsSpanned());

        $notes = Markup::html('<table><tr><td>a</td></tr><tr><td rowspan="5">b</td></tr></table>')->notes();
        self::assertSame(
            [[FidelityLevel::Approximated, 'markup rowspan (clamped to the table section)', 'table[0] > tbody[0] > tr[1] > td[0]']],
            self::noteList($notes),
        );
        $clamped = self::table('<table><tr><td>a</td></tr><tr><td rowspan="5">b</td></tr></table>');
        self::assertSame(1, $clamped->bodyRows()[1]->cells()[0]->rowsSpanned());

        $partly = self::table('<table><tr><td rowspan="5">a</td></tr><tr><td>b</td></tr><tr><td>c</td></tr></table>');
        self::assertSame(3, $partly->bodyRows()[0]->cells()[0]->rowsSpanned());

        $silent = Markup::html('<table><tr><td colspan="0" rowspan="-1">a</td></tr></table>');
        self::assertSame([], $silent->notes());
    }

    public function test_hostile_spans_are_clamped_and_noted(): void
    {
        $v = self::view('<table><tr><td colspan="99999" rowspan="99999">a</td></tr><tr><td>b</td></tr></table>');
        $table = $v->children()[0];
        self::assertInstanceOf(Table::class, $table);
        $cell = $table->bodyRows()[0]->cells()[0];
        self::assertSame(1000, $cell->span());
        self::assertSame(2, $cell->rowsSpanned());
        self::assertNotSame([], $v->notes());

        $start = microtime(true);
        Document::make(Page::letter()->body($v))->toPdf(new PdfOptions(strict: false, cacheDir: false));
        self::assertLessThan(2.0, microtime(true) - $start);
    }

    public function test_cell_content_converts_in_its_own_frame(): void
    {
        $o = new MarkupOptions(images: fn (string $s): string => TestImages::png());
        $table = self::table('<table><tr><td><p>One</p><ul><li>x</li></ul><img src="i.png"></td></tr></table>', $o);
        $children = $table->bodyRows()[0]->cells()[0]->children();

        self::assertCount(3, $children);
        self::assertInstanceOf(Text::class, $children[0]);
        self::assertSame('One', self::plain($children[0]));
        self::assertSame('p', $children[0]->preset());
        self::assertInstanceOf(ListNode::class, $children[1]);
        self::assertInstanceOf(Image::class, $children[2]);
    }

    public function test_nested_table_is_flattened_and_noted(): void
    {
        $v = self::view('<table><tr><td>Outer<table><tr><td>in1</td><td>in2</td></tr></table></td></tr></table>');
        $table = $v->children()[0];
        self::assertInstanceOf(Table::class, $table);
        $children = $table->bodyRows()[0]->cells()[0]->children();

        self::assertContainsOnlyInstancesOf(Text::class, $children);
        self::assertSame(['Outer', 'in1', 'in2'], array_map(fn (Text $t) => self::plain($t), $children));
        self::assertSame(['p', 'p', 'p'], array_map(fn (Text $t) => $t->preset(), $children));
        self::assertSame(
            [[FidelityLevel::Approximated, 'markup nested <table> (flattened)', 'table[0] > tbody[0] > tr[0] > td[0] > table[0]']],
            self::noteList($v->notes()),
        );
    }

    public function test_stray_text_in_table_goes_before_it(): void
    {
        $v = Markup::html('<table>loose<tr><td>a</td></tr></table>');

        self::assertInstanceOf(View::class, $v);
        self::assertCount(2, $v->children());
        self::assertInstanceOf(Text::class, $v->children()[0]);
        self::assertSame('loose', self::plain($v->children()[0]));
        self::assertInstanceOf(Table::class, $v->children()[1]);
    }

    public function test_stray_cells_outside_a_table_keep_todays_behaviour(): void
    {
        $t = Markup::html('<td>a</td>');

        self::assertInstanceOf(Text::class, $t);
        self::assertSame('a', self::plain($t));
    }

    public function test_empty_table_is_nothing(): void
    {
        foreach (['<table></table>', '<table><tr></tr></table>', '<table><thead></thead><tbody><tr></tr></tbody></table>'] as $html) {
            $v = Markup::html($html);
            self::assertInstanceOf(View::class, $v, $html);
            self::assertSame([], $v->children(), $html);
        }
    }

    public function test_ragged_html_table_converts_without_notes_for_missing_cells(): void
    {
        $v = self::view('<table><tr><td>a</td><td>b</td><td>c</td></tr><tr><td>d</td></tr><tr><td>e</td><td>f</td></tr></table>');
        $table = $v->children()[0];
        self::assertInstanceOf(Table::class, $table);

        self::assertCount(3, $table->bodyRows());
        self::assertSame([], $v->notes());
        self::assertCount(3, self::tracks($table));
    }

    public function test_ordinary_table_attributes_make_no_notes(): void
    {
        $v = Markup::html('<table border="1" cellpadding="2" cellspacing="0" class="x" id="t" summary="s"><colgroup><col span="2"></colgroup><tr class="r" id="r"><td class="c" id="c" summary="s" border="1">a</td><td>b</td></tr></table>');

        self::assertSame([], $v->notes());
    }

    public function test_table_inside_list_item_stays_in_the_item(): void
    {
        $v = Markup::html('<ul><li>Intro<table><tr><td>a</td></tr></table></li></ul>');

        self::assertInstanceOf(View::class, $v);
        self::assertCount(1, $v->children());
        $list = $v->children()[0];
        self::assertInstanceOf(ListNode::class, $list);
        $item = $list->items()[0];
        self::assertInstanceOf(ListItem::class, $item);
        self::assertInstanceOf(Text::class, $item->children()[0]);
        self::assertInstanceOf(Table::class, $item->children()[count($item->children()) - 1]);
    }

    public function test_theme_styles_give_a_single_line_grid(): void
    {
        $slate = Color::named('slate-300');
        $table = DefaultStyles::get('markup.table');
        $td = DefaultStyles::get('markup.td');
        $th = DefaultStyles::get('markup.th');
        self::assertNotNull($table);
        self::assertNotNull($td);
        self::assertNotNull($th);

        self::assertSame([0.75, 0.0, 0.0, 0.75], self::widths($table));
        self::assertEquals($slate, $table->borderColor);
        self::assertSame([0.0, 0.75, 0.75, 0.0], self::widths($td));
        self::assertEquals($slate, $td->borderColor);
        self::assertNull($td->padding);
        self::assertNull($th->padding);
        self::assertSame(self::widths($td), self::widths($th));
        self::assertEquals(Color::named('slate-50'), $th->background);
        self::assertNull($td->background);
    }

    public function test_table_grid_of_a_converted_table_has_the_track_count(): void
    {
        $table = self::table('<table><tr><td colspan="3">a</td></tr><tr><td>b</td></tr></table>');

        self::assertSame(3, TableGrid::of($table)->columns);
        self::assertCount(3, self::tracks($table));
    }

    /** @return list<\Papercut\Value\Length|\Papercut\Value\Percent|\Papercut\Value\Fr|Auto> */
    private static function tracks(Table $table): array
    {
        $list = $table->trackList();
        self::assertNotNull($list);
        return $list->tracks;
    }

    private static function view(string $html, ?MarkupOptions $options = null): View
    {
        $v = Markup::html($html, $options ?? new MarkupOptions());
        self::assertInstanceOf(View::class, $v);
        return $v;
    }

    private static function table(string $html, ?MarkupOptions $options = null): Table
    {
        $v = Markup::html($html, $options ?? new MarkupOptions());
        self::assertInstanceOf(View::class, $v);
        self::assertCount(1, $v->children());
        $table = $v->children()[0];
        self::assertInstanceOf(Table::class, $table);
        return $table;
    }

    /** @return list<string> */
    private static function cellTexts(TableCell $cell): array
    {
        $texts = [];
        foreach ($cell->children() as $child) {
            self::assertInstanceOf(Text::class, $child);
            $texts[] = self::plain($child);
        }
        return $texts;
    }

    /**
     * @param list<TableRow> $rows
     * @return list<list<string>>
     */
    private static function rowTexts(array $rows): array
    {
        return array_map(fn (TableRow $r) => array_map(fn (TableCell $c) => implode('', self::cellTexts($c)), $r->cells()), $rows);
    }

    private static function plain(Text $t): string
    {
        return implode('', array_map(fn ($s) => $s->text, $t->spanList()));
    }

    /**
     * @param list<Note> $notes
     * @return list<array{FidelityLevel, string, string}>
     */
    private static function noteList(array $notes): array
    {
        return array_map(fn (Note $x) => [$x->level, $x->feature, $x->where], $notes);
    }

    public function test_cell_width_sets_its_column_in_original_mode(): void
    {
        $table = self::table('<table><tr><td width="120">a</td><td style="width:25%">b</td><td>c</td></tr></table>', self::original());

        self::assertEquals([Length::of(120, Unit::Px), Percent::of(25), Auto::instance()], self::tracks($table));
        foreach ($table->bodyRows()[0]->cells() as $cell) {
            self::assertNull($cell->style()->width);
        }
    }

    public function test_first_width_in_a_column_wins_and_spanning_cells_set_none(): void
    {
        $table = self::table('<table><tr><td colspan="2" width="300">a</td></tr><tr><td width="50">b</td><td width="60">c</td></tr></table>', self::original());

        self::assertEquals([Length::points(37.5), Length::points(45)], self::tracks($table));

        self::assertEquals([Length::points(37.5)], self::tracks(self::table('<table><tr><td width="50">a</td></tr><tr><td width="80">b</td></tr></table>', self::original())));
    }

    public function test_widths_are_ignored_in_theme_mode(): void
    {
        $table = self::table('<table width="400"><tr><td colspan="2" width="300">a</td></tr><tr><td width="50">b</td><td style="width:60px">c</td></tr></table>');

        self::assertSame([Auto::instance(), Auto::instance()], self::tracks($table));
        self::assertNull($table->style()->width);
    }

    public function test_table_width_sets_the_table_width(): void
    {
        $attribute = self::table('<table width="400"><tr><td>a</td></tr></table>', self::original());
        $style = self::table('<table style="width:50%"><tr><td>a</td></tr></table>', self::original());

        self::assertEquals(Length::points(300), $attribute->style()->width);
        self::assertEquals(Percent::of(50), $style->style()->width);
    }

    public function test_cell_inline_style_applies_in_original_mode(): void
    {
        $v = Markup::html('<table><tr><td style="background-color:#fee;text-align:right;border:1px solid red">a</td></tr></table>', self::original());
        $cell = self::table('<table><tr><td style="background-color:#fee;text-align:right">a</td></tr></table>', self::original())->bodyRows()[0]->cells()[0];

        self::assertNotNull($cell->style()->background);
        self::assertSame(\Papercut\Style\TextAlign::Right, $cell->style()->textAlign);
        self::assertSame([[FidelityLevel::Dropped, 'markup CSS "border:1px solid red"']], array_map(
            static fn (Note $n): array => [$n->level, $n->feature],
            $v->notes(),
        ));
    }

    public function test_hostile_widths_leave_the_track_auto_and_never_throw(): void
    {
        $long = str_repeat('9', 400);
        foreach (['-5', '0', 'abc', $long, $long . '%', '-5%', '0%', '5e3'] as $w) {
            $table = self::table('<table width="' . $w . '"><tr><td width="' . $w . '">a</td></tr></table>', self::original());
            self::assertSame([Auto::instance()], self::tracks($table), $w);
            self::assertNull($table->style()->width, $w);
        }
        self::assertEquals([Percent::of(100)], self::tracks(self::table('<table><tr><td width="150%">a</td></tr></table>', self::original())));
    }

    public function test_cell_and_table_sizes_other_than_width_are_dropped_and_noted(): void
    {
        foreach (['height:99999px', 'max-width:1px', 'min-width:5000px', 'min-height:9px', 'max-height:1px'] as $d) {
            $html = '<table style="' . $d . '"><tr><td style="' . $d . ';width:50%">a</td></tr></table>';
            $v = Markup::html($html, self::original());
            self::assertEquals([Percent::of(50)], self::tracks(self::table($html, self::original())));
            $dropped = array_map(static fn (Note $n): string => $n->feature, $v->notes());
            self::assertSame(['markup CSS "' . $d . '"', 'markup CSS "' . $d . '"'], $dropped);
            $table = self::table($html, self::original());
            self::assertNull($table->style()->height);
            self::assertNull($table->bodyRows()[0]->cells()[0]->style()->maxWidth);
            $bytes = Document::make(Page::letter()->body($v))->toPdf(new PdfOptions(strict: false, cacheDir: false))->bytes();
            self::assertStringStartsWith('%PDF', $bytes);
        }
    }

    // --- final fix wave ------------------------------------------------------

    public function test_caption_keeps_with_the_table(): void
    {
        $v = self::view('<table><caption>Table 3. Guidance</caption><tr><td>a</td></tr></table>');

        self::assertCount(2, $v->children());
        self::assertTrue($v->children()[0] instanceof Text && $v->children()[0]->style()->keepWithNext === true);
        self::assertInstanceOf(Table::class, $v->children()[1]);
    }

    public function test_table_style_applies_to_the_table_not_to_a_wrapper(): void
    {
        $v = self::view('<table style="background-color:#eeeeee"><caption>C</caption><tr><td>a</td></tr></table>', self::original());

        self::assertCount(2, $v->children());
        self::assertInstanceOf(Text::class, $v->children()[0]);
        self::assertNull($v->children()[0]->style()->background);
        $table = $v->children()[1];
        self::assertInstanceOf(Table::class, $table);
        self::assertNotNull($table->style()->background);
    }

    public function test_repeated_table_part_declarations_are_noted_once_per_table(): void
    {
        $cell = '<td style="height:15.0pt;border:.5pt solid windowtext;margin:5px">x</td>';
        $rows = str_repeat('<tr>' . $cell . $cell . '</tr>', 30);
        $v = self::view('<table>' . $rows . '</table>', self::original());

        $features = array_map(static fn (Note $n): string => $n->feature, $v->notes());
        self::assertEqualsCanonicalizing(['markup CSS "height:15.0pt"', 'markup CSS "border:.5pt solid windowtext"', 'markup CSS "margin:5px"'], $features);
        self::assertSame('table[0] > tbody[0] > tr[0] > td[0]', $v->notes()[0]->where);
        $table = $v->children()[0];
        self::assertInstanceOf(Table::class, $table);
        self::assertNull($table->bodyRows()[0]->cells()[0]->style()->margin);
    }

    public function test_css_inside_cell_content_is_noted_once_per_table(): void
    {
        $row = '<tr><td><p class="MsoNormal" style="margin-bottom:0in;line-height:normal;float:left"><span style="mso-ascii-font-family:Calibri">x</span></p></td></tr>';
        $v = self::view('<table>' . str_repeat($row, 30) . '</table>', self::original());

        $features = array_map(static fn (Note $n): string => $n->feature, $v->notes());
        self::assertEqualsCanonicalizing([
            'markup CSS "margin-bottom:0in"',
            'markup CSS "line-height:normal"',
            'markup float (rendered as a block)',
            'markup CSS "mso-ascii-font-family:Calibri"',
        ], $features);
        self::assertSame('table[0] > tbody[0] > tr[0] > td[0] > p[0]', $v->notes()[0]->where);
    }

    public function test_the_same_declaration_in_two_tables_is_noted_for_each(): void
    {
        $t = '<table><tr><td style="height:9pt">x</td><td style="height:9pt">y</td></tr></table>';
        $v = self::view($t . $t, self::original());

        self::assertCount(2, $v->notes());
    }

    public function test_span_clamp_notes_are_once_per_table(): void
    {
        $colspans = str_repeat('<tr><td colspan="99999">a</td></tr>', 50);
        $rowspans = str_repeat('<tr><td rowspan="99999">a</td></tr>', 50);
        $features = static fn (View $v): array => array_map(static fn (Note $n): string => $n->feature, $v->notes());

        self::assertSame(['markup colspan (clamped to 1000)'], $features(self::view('<table>' . $colspans . '</table>')));
        self::assertSame(['markup rowspan (clamped to 1000)', 'markup rowspan (clamped to the table section)'], $features(self::view('<table>' . $rowspans . '</table>')));
    }

    public function test_declared_table_width_is_kept_as_written(): void
    {
        foreach (['800' => Length::points(600), '150%' => Percent::of(150), '650' => Length::points(487.5), '300' => Length::points(225)] as $w => $expected) {
            $v = self::view('<table width="' . $w . '"><tr><td>a</td></tr></table>', self::original());
            $table = $v->children()[0];
            self::assertInstanceOf(Table::class, $table);
            self::assertEquals($expected, $table->style()->width, (string) $w);
            self::assertEquals(Percent::of(100), $table->style()->maxWidth, (string) $w);
            self::assertSame([], $v->notes(), (string) $w);
        }
    }

    public function test_length_columns_are_kept_and_marked_to_fit(): void
    {
        $v = self::view('<table><tr><td width="300">a</td><td width="300">b</td><td width="300">c</td><td width="300">d</td></tr></table>', self::original());
        $table = $v->children()[0];
        self::assertInstanceOf(Table::class, $table);
        self::assertEquals(array_fill(0, 4, Length::points(225)), self::tracks($table));
        self::assertTrue($table->fitsColumns());
        self::assertSame([], $v->notes());
    }

    public function test_percent_columns_over_one_hundred_are_scaled_to_one_hundred(): void
    {
        $v = self::view('<table><tr><td width="60%">a</td><td width="60%">b</td></tr></table>', self::original());
        $table = $v->children()[0];
        self::assertInstanceOf(Table::class, $table);
        self::assertEquals([Percent::of(50), Percent::of(50)], self::tracks($table));
        self::assertSame(['markup column widths (wider than the page; fitted)'], array_map(static fn (Note $n): string => $n->feature, $v->notes()));
        self::assertEquals([Percent::of(100)], self::tracks(self::table('<table><tr><td width="150%">a</td></tr></table>', self::original())));
    }

    /** @return array<string, array{string, float, ?float}> html, container width, declared extent in pt that must be kept when it fits */
    public static function widthCases(): array
    {
        $cols = static fn (int $n, int $px): string => '<table><tr>' . str_repeat('<td width="' . $px . '">a</td>', $n) . '</tr></table>';
        $cases = [];
        foreach (['A6' => 184.2, 'A5' => 306.1, 'A4' => 481.89, 'Letter' => 498.6, 'A4 landscape' => 728.5] as $page => $w) {
            $cases["$page 2x300px"] = [$cols(2, 300), $w, 450.0];
            $cases["$page 2x400px"] = [$cols(2, 400), $w, 600.0];
            $cases["$page 4x300px"] = [$cols(4, 300), $w, 900.0];
            $cases["$page table 650px"] = ['<table width="650"><tr><td>a</td><td>b</td></tr></table>', $w, 487.5];
            $cases["$page table 900px"] = ['<table width="900"><tr><td>a</td><td>b</td></tr></table>', $w, 675.0];
            $cases["$page table 400px + 2x300px"] = ['<table width="400"><tr><td width="300">a</td><td width="300">b</td></tr></table>', $w, null];
            $cases["$page 2x300px + auto"] = ['<table><tr><td width="300">a</td><td width="300">b</td><td>c</td></tr></table>', $w, null];
        }
        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('widthCases')]
    public function test_original_tables_fit_the_width_they_are_given(string $html, float $container, ?float $extent): void
    {
        $harness = new \Papercut\Tests\Support\LayoutHarness();
        $v = self::view($html, self::original());
        $r = $harness->layout($v, $container);
        self::assertNotNull($r->fragment);
        self::assertLessThanOrEqual($container + 1e-6, $r->fragment->children()[0]['fragment']->width);
        self::assertSame([], $harness->warnings);
        self::assertSame([], $v->notes());
        if ($extent !== null && $extent <= $container - 1) {
            $table = $v->children()[0];
            self::assertInstanceOf(Table::class, $table);
            $first = self::tracks($table)[0];
            $runs = \Papercut\Tests\Support\LayoutHarness::runs($r->fragment);
            $width = $table->style()->width;
            if ($width instanceof Length) {
                self::assertEqualsWithDelta($width->points, $r->fragment->children()[0]['fragment']->width, 0.01, 'declared table width kept');
            }
            $declared = $first instanceof Length ? $first->points : null;
            if ($declared !== null) {
                self::assertEqualsWithDelta($declared, $runs[1]['x'] - $runs[0]['x'], 0.01, 'declared column width kept');
            }
        }
    }

    public function test_columns_wider_than_a_narrower_declared_table_width_fit_inside_it(): void
    {
        $harness = new \Papercut\Tests\Support\LayoutHarness();
        $v = self::view('<table width="400"><tr><td width="300">a</td><td width="300">b</td></tr></table>', self::original());
        $r = $harness->layout($v, 481.89);
        self::assertNotNull($r->fragment);
        self::assertEqualsWithDelta(300.0, $r->fragment->children()[0]['fragment']->width, 0.01);
        $runs = \Papercut\Tests\Support\LayoutHarness::runs($r->fragment);
        self::assertEqualsWithDelta(150.0, $runs[1]['x'] - $runs[0]['x'], 0.5);
        self::assertSame([], $harness->warnings);
    }

    public function test_original_table_fits_inside_a_list_item(): void
    {
        $harness = new \Papercut\Tests\Support\LayoutHarness();
        $v = self::view('<ul><li><table><tr><td width="300">a</td><td width="300">b</td></tr></table></li></ul>', self::original());
        $r = $harness->layout($v, 481.89);
        self::assertNotNull($r->fragment);
        self::assertSame([], $harness->warnings);
        $runs = \Papercut\Tests\Support\LayoutHarness::runs($r->fragment);
        self::assertLessThanOrEqual(481.89, max([0.0, ...array_column($runs, 'x')]));
        $table = $r->fragment->children()[0]['fragment'];
        self::assertLessThanOrEqual(481.89 + 1e-6, $table->width);
    }

    public function test_fill_width_splits_evenly_when_no_column_has_content(): void
    {
        $table = Table::make()->columns('auto auto')->fillWidth()->row(TableCell::make()->padding(\Papercut\pt(0)), TableCell::make()->padding(\Papercut\pt(0)));
        $widths = (new \Papercut\Layout\TableLayouter(new \Papercut\Layout\BlockLayouter()))->columns($table, \Papercut\Style\ComputedStyle::root(\Papercut\Style\Theme::default()), 200.0, (new \Papercut\Tests\Support\LayoutHarness())->engine);
        self::assertEqualsWithDelta([100.0, 100.0], $widths, 1e-6);
    }

    private static function original(): MarkupOptions
    {
        return new MarkupOptions(styles: \Papercut\Markup\StyleMode::Original);
    }

    /** @return list<float> top, right, bottom, left in points */
    private static function widths(\Papercut\Style\Style $style): array
    {
        $e = $style->borderWidth;
        self::assertNotNull($e);
        return [$e->top->points, $e->right->points, $e->bottom->points, $e->left->points];
    }
}
