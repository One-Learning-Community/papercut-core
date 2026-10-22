<?php

declare(strict_types=1);

namespace Papercut\Tests\Style;

use Papercut\{TableCell, Text};
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
        foreach ($cells as $label => $cell) {
            $viaTable = $r->resolve($prepare->invoke(null, $cell, $theme), $r->root());
            $viaResolver = $r->resolveInTable($cell, $r->root());
            self::assertEquals($viaTable->padding, $viaResolver->padding, "$label padding");
            self::assertSame($viaTable->fontWeight, $viaResolver->fontWeight, "$label weight");
        }
    }
}
