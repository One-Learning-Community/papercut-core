<?php

declare(strict_types=1);

namespace Papercut\Tests\Style;

use Papercut\{Column, ListItem, TableCell, Text};
use Papercut\Style\{Style, StyleResolver, Theme};
use Papercut\Value\{Color, Edges};
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

final class StyleResolverTest extends TestCase
{
    public function test_layers_apply_preset_then_named_then_own(): void
    {
        $theme = Theme::default()->withStyle('loud', Style::make(fontSize: pt(30), color: Color::parse('red-500')));
        $r = new StyleResolver($theme);
        $s = $r->resolve(Text::h1('x')->apply('loud')->color('blue-500'), $r->root());
        self::assertSame(30.0, $s->fontSize);
        self::assertEquals(Color::parse('blue-500'), $s->color);
        self::assertSame(700, $s->fontWeight, 'h1 preset weight survives under named and own');
        self::assertSame(400, $r->resolve(Text::make('x'), $r->root())->fontWeight);
    }

    public function test_memoized_by_node_and_parent_identity(): void
    {
        $r = new StyleResolver(Theme::default());
        $node = Text::make('x');
        self::assertSame($r->resolve($node, $r->root()), $r->resolve($node, $r->root()));
        self::assertSame($r->root(), $r->root());
        $other = $r->root()->child(Style::make(fontSize: pt(20)));
        self::assertNotSame($r->resolve($node, $r->root()), $r->resolve($node, $other));
    }

    public function test_bare_cell_through_resolve_gets_no_table_defaults(): void
    {
        $r = new StyleResolver(Theme::default());
        $th = $r->resolve(TableCell::th('a'), $r->root());
        self::assertSame(400, $th->fontWeight);
        self::assertEquals($r->root()->padding, $th->padding);
        self::assertEquals($r->root()->padding, $r->resolve(TableCell::make('a'), $r->root())->padding);
    }

    public function test_resolve_in_table_applies_cell_defaults(): void
    {
        $r = new StyleResolver(Theme::default());
        $td = $r->resolveInTable(TableCell::make('a'), $r->root());
        self::assertEquals(Theme::default()->cellPadding, $td->padding);
        self::assertSame(400, $td->fontWeight);
        self::assertSame(700, $r->resolveInTable(TableCell::th('a'), $r->root())->fontWeight);
        self::assertSame(400, $r->resolveInTable(TableCell::th('a')->weight(400), $r->root())->fontWeight);
        self::assertEquals(Edges::all(pt(1)), $r->resolveInTable(TableCell::make('a')->padding(pt(1)), $r->root())->padding);
    }

    public function test_resolve_in_table_named_style_beats_defaults(): void
    {
        $theme = Theme::default()->withStyle('roomy', Style::make(padding: Edges::all(pt(9)), fontWeight: 300));
        $r = new StyleResolver($theme);
        $s = $r->resolveInTable(TableCell::th('a')->apply('roomy'), $r->root());
        self::assertEquals(Edges::all(pt(9)), $s->padding);
        self::assertSame(300, $s->fontWeight);
    }

    public function test_resolve_in_table_has_its_own_memo(): void
    {
        $r = new StyleResolver(Theme::default());
        $cell = TableCell::th('a');
        self::assertSame($r->resolveInTable($cell, $r->root()), $r->resolveInTable($cell, $r->root()));
        self::assertNotSame($r->resolve($cell, $r->root()), $r->resolveInTable($cell, $r->root()));
    }

    public function test_resolve_in_table_matches_table_layouter_defaults(): void
    {
        $theme = Theme::default()
            ->withStyle('roomy', Style::make(padding: Edges::all(pt(9))))
            ->withStyle('light', Style::make(fontWeight: 300));
        $r = new StyleResolver($theme);
        $prepare = new \ReflectionMethod(\Papercut\Layout\TableLayouter::class, 'withThemeDefaults');
        $cells = [
            'td' => TableCell::make('a'),
            'th' => TableCell::th('a'),
            'own padding' => TableCell::th('a')->padding(pt(1)),
            'own weight' => TableCell::th('a')->weight(400),
            'named padding' => TableCell::th('a')->apply('roomy'),
            'named weight' => TableCell::th('a')->apply('light'),
        ];
        // Layout resolves cell copies through resolveInTable() (StyleResolver::copyOf()), so the two must agree
        // in full, also under a row inside a list item.
        $inList = $r->resolve(ListItem::make('item'), $r->root());
        foreach (['at the top' => $r->root(), 'in a list' => $inList] as $where => $row) {
            foreach ($cells as $label => $cell) {
                $viaTable = $r->resolve($prepare->invoke(null, $cell, $theme), $row);
                $viaResolver = $r->resolveInTable($cell, $row);
                self::assertEquals($viaTable, $viaResolver, "$label, $where");
            }
        }
    }

    public function test_a_table_cell_is_never_in_a_list(): void
    {
        $r = new StyleResolver(Theme::default());
        $row = $r->resolve(ListItem::make('item'), $r->root());
        self::assertTrue($row->inList);
        self::assertFalse($r->resolveInTable(TableCell::make('a'), $row)->inList);
        self::assertFalse($r->resolve(TableCell::make('a'), $row)->inList, 'a cell resolved on its own, as layout does a pinned cell');
        self::assertFalse($r->resolve(TableCell::make('a')->width(pt(300)), $row)->inList);
    }

    public function test_a_registered_cell_copy_resolves_as_its_cell_does_in_a_table(): void
    {
        $r = new StyleResolver(Theme::default());
        $cell = TableCell::th('a');
        $copy = $cell->padding(pt(3));
        $r->copyOf($copy, $cell);
        self::assertSame($r->resolveInTable($cell, $r->root()), $r->resolve($copy, $r->root()));
    }

    public function test_a_wrapper_column_with_nothing_of_its_own_resolves_to_its_parent(): void
    {
        $r = new StyleResolver(Theme::default());
        $column = Column::make(Text::make('a'));
        $r->wrapper($column);
        self::assertSame($r->root(), $r->resolve($column, $r->root()));
        self::assertNotSame($r->root(), $r->resolve(Column::make(Text::make('a')), $r->root()), 'an unregistered column gets its own style');
    }

    public function test_a_wrapper_column_with_a_style_of_its_own_resolves_normally(): void
    {
        $r = new StyleResolver(Theme::default());
        $column = Column::make(Text::make('a'))->padding(pt(4));
        $r->wrapper($column);
        $style = $r->resolve($column, $r->root());
        self::assertNotSame($r->root(), $style);
        self::assertEquals(Edges::all(pt(4)), $style->padding);
    }

    public function test_nodes_with_the_same_style_share_one_computed_style(): void
    {
        $r = new StyleResolver(Theme::default());
        $a = $r->resolve(Text::make('one')->right()->bold(), $r->root());
        $b = $r->resolve(Text::make('two')->bold()->right(), $r->root());
        self::assertSame($a, $b);
        self::assertNotSame($a, $r->resolve(Text::make('three')->right(), $r->root()));
    }

    public function test_a_node_under_a_second_parent_resolves_and_is_remembered_under_each(): void
    {
        $r = new StyleResolver(Theme::default());
        $text = Text::make('a');
        $big = $r->resolve(Column::make()->size(pt(20)), $r->root());
        $first = $r->resolve($text, $r->root());
        $second = $r->resolve($text, $big);
        self::assertSame(20.0, $second->fontSize);
        self::assertNotSame($first, $second);
        self::assertSame($first, $r->resolve($text, $r->root()));
        self::assertSame($second, $r->resolve($text, $big));
    }
}
