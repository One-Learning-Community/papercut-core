<?php

declare(strict_types=1);

namespace Papercut\Tests\Tree;

use Papercut\Decorator\Card;
use Papercut\{Column, ColumnBreak, ColumnFlow, Document, Node, Page, Row, Table, TableCell, TableRow, Text, View};
use Papercut\Exception\InvalidStructure;
use Papercut\Style\{Align, ComputedStyle, Direction, StyleResolver, Theme};
use Papercut\Tree\{ColumnFlowPlacement, ColumnFlows, Expander, Region, TreeVisitor, TreeWalker, Visit};
use Papercut\Value\{Auto, Color, Edges, Length};
use Papercut\Layout\{BreakToken, Constraints, Fragment, LayoutContext, LayoutEngine, LayoutResult, Layouter, Paginator};
use Papercut\Tests\Support\FakeBackend;
use Papercut\Text\FontRegistry;
use Papercut\OverflowMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ColumnFlowsTest extends TestCase
{
    private static function root(): ComputedStyle
    {
        return ComputedStyle::root(Theme::default());
    }

    /** @return array{node: Node, style: ComputedStyle} */
    private static function entry(Node $node, ?ComputedStyle $style = null): array
    {
        return ['node' => $node, 'style' => $style ?? self::root()];
    }

    private static function with(string $property, mixed $value): ComputedStyle
    {
        $r = self::root();
        $args = get_object_vars($r);
        $args[$property] = $value;
        return new ComputedStyle(...$args);
    }

    public function test_is_flow_is_true_only_for_a_view_with_a_column_spec(): void
    {
        self::assertTrue(ColumnFlows::isFlow(ColumnFlow::make(Text::make('a'))));
        self::assertFalse(ColumnFlows::isFlow(Column::make(Text::make('a'))));
        self::assertFalse(ColumnFlows::isFlow(Text::make('a')));
    }

    public function test_placement_on_page_with_no_ancestors(): void
    {
        self::assertSame(ColumnFlowPlacement::OnPage, ColumnFlows::placement([], Region::Body));
    }

    public function test_placement_on_page_through_a_wrapper_with_only_a_vertical_margin(): void
    {
        $margin = self::with('margin', Edges::of(Length::points(10), Length::zero()));
        self::assertSame(ColumnFlowPlacement::OnPage, ColumnFlows::placement([self::entry(Column::make(), $margin)], Region::Body));
    }

    public function test_placement_in_box_for_a_wrapper_with_padding(): void
    {
        $padded = self::with('padding', Edges::all(Length::points(4)));
        self::assertSame(ColumnFlowPlacement::InBox, ColumnFlows::placement([self::entry(Column::make(), $padded)], Region::Body));
    }

    public function test_placement_in_box_for_a_card_expanded_to_its_view(): void
    {
        $theme = Theme::default();
        $resolver = new StyleResolver($theme);
        $view = Expander::expand(Card::make(Text::make('a')), $theme);
        $style = $resolver->resolve($view, ComputedStyle::root($theme));
        self::assertSame(ColumnFlowPlacement::InBox, ColumnFlows::placement([self::entry($view, $style)], Region::Body));
    }

    public function test_placement_in_cell(): void
    {
        self::assertSame(ColumnFlowPlacement::InCell, ColumnFlows::placement([self::entry(TableCell::make())], Region::Body));
    }

    public function test_placement_in_cell_wins_over_a_box_below_it(): void
    {
        $padded = self::with('padding', Edges::all(Length::points(4)));
        $ancestors = [self::entry(TableCell::make()), self::entry(Column::make(), $padded)];
        self::assertSame(ColumnFlowPlacement::InCell, ColumnFlows::placement($ancestors, Region::Body));
    }

    public function test_placement_in_cell_wins_over_a_box_above_it(): void
    {
        $padded = self::with('padding', Edges::all(Length::points(4)));
        $ancestors = [self::entry(Column::make(), $padded), self::entry(TableCell::make())];
        self::assertSame(ColumnFlowPlacement::InCell, ColumnFlows::placement($ancestors, Region::Body));
    }

    public function test_placement_in_row(): void
    {
        $row = self::with('direction', Direction::Row);
        self::assertSame(ColumnFlowPlacement::InRow, ColumnFlows::placement([self::entry(Row::make(), $row)], Region::Body));
    }

    public function test_placement_in_flow(): void
    {
        self::assertSame(ColumnFlowPlacement::InFlow, ColumnFlows::placement([self::entry(ColumnFlow::make())], Region::Body));
    }

    public function test_placement_outside_body(): void
    {
        foreach ([Region::Header, Region::Footer, Region::Background, Region::Overlay, Region::FirstHeader, Region::EvenFooter] as $region) {
            self::assertSame(ColumnFlowPlacement::OutsideBody, ColumnFlows::placement([], $region), $region->value);
        }
    }

    public function test_only_the_four_blocked_placements_are_blocked(): void
    {
        $blocked = array_filter(ColumnFlowPlacement::cases(), fn (ColumnFlowPlacement $p): bool => $p->isBlocked());
        self::assertEqualsCanonicalizing(
            [ColumnFlowPlacement::InCell, ColumnFlowPlacement::InRow, ColumnFlowPlacement::InFlow, ColumnFlowPlacement::OutsideBody],
            array_values($blocked),
        );
    }

    public function test_a_plain_wrapper_is_a_default_column_view(): void
    {
        self::assertTrue(ColumnFlows::isPlainWrapper(Column::make(), self::root()));
        self::assertTrue(ColumnFlows::isPlainWrapper(Column::make(), self::with('alignSelf', Align::Stretch)));
    }

    public function test_a_flow_text_and_a_cell_are_not_plain_wrappers(): void
    {
        self::assertFalse(ColumnFlows::isPlainWrapper(ColumnFlow::make(), self::root()));
        self::assertFalse(ColumnFlows::isPlainWrapper(Text::make('a'), self::root()));
        self::assertFalse(ColumnFlows::isPlainWrapper(TableCell::make(), self::root()));
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function nonDefaultProperties(): iterable
    {
        $edge = Edges::of(Length::points(1), Length::zero(), Length::zero(), Length::zero());
        yield 'border width' => ['borderWidth', Edges::all(Length::points(1))];
        yield 'background' => ['background', Color::black()];
        yield 'padding' => ['padding', $edge];
        yield 'margin left' => ['margin', Edges::of(Length::zero(), Length::zero(), Length::zero(), Length::points(2))];
        yield 'margin right' => ['margin', Edges::of(Length::zero(), Length::points(2), Length::zero(), Length::zero())];
        yield 'width' => ['width', Length::points(100)];
        yield 'min width' => ['minWidth', Length::points(100)];
        yield 'max width' => ['maxWidth', Length::points(100)];
        yield 'height' => ['height', Length::points(100)];
        yield 'min height' => ['minHeight', Length::points(100)];
        yield 'max height' => ['maxHeight', Length::points(100)];
        yield 'keep together' => ['keepTogether', true];
        yield 'row direction' => ['direction', Direction::Row];
        yield 'align items' => ['alignItems', Align::Center];
        yield 'align self' => ['alignSelf', Align::End];
    }

    #[DataProvider('nonDefaultProperties')]
    public function test_plain_wrapper_rejects_each_listed_property(string $property, mixed $value): void
    {
        self::assertFalse(ColumnFlows::isPlainWrapper(Column::make(), self::with($property, $value)));
    }

    public function test_column_break_allowed_only_under_a_flow_without_a_cell_or_row_between(): void
    {
        $flow = self::entry(ColumnFlow::make());
        $cell = self::entry(TableCell::make());
        $row = self::entry(Row::make(), self::with('direction', Direction::Row));
        $box = self::entry(Column::make());

        self::assertFalse(ColumnFlows::columnBreakAllowed([]));
        self::assertFalse(ColumnFlows::columnBreakAllowed([$box]));
        self::assertTrue(ColumnFlows::columnBreakAllowed([$flow]));
        self::assertTrue(ColumnFlows::columnBreakAllowed([$flow, $box]));
        self::assertFalse(ColumnFlows::columnBreakAllowed([$flow, $cell]));
        self::assertFalse(ColumnFlows::columnBreakAllowed([$flow, $row, $box]));
        // A cell or row above the nearest flow does not matter.
        self::assertTrue(ColumnFlows::columnBreakAllowed([$cell, $row, $flow, $box]));
        self::assertTrue(ColumnFlows::columnBreakAllowed([$flow, $cell, $flow]));
    }

    public function test_misplaced_names_the_path_and_the_place(): void
    {
        $path = 'page > body > table > row[0] > cell[1] > column-flow';
        $e = ColumnFlows::misplaced(ColumnFlowPlacement::InCell, $path);
        self::assertInstanceOf(InvalidStructure::class, $e);
        self::assertSame(
            'A ColumnFlow can\'t go inside a table cell (at "page > body > table > row[0] > cell[1] > column-flow"). Columns work on the page or inside a box.',
            $e->getMessage(),
        );
        self::assertStringContainsString('inside a row', ColumnFlows::misplaced(ColumnFlowPlacement::InRow, 'p')->getMessage());
        self::assertStringContainsString('inside another ColumnFlow', ColumnFlows::misplaced(ColumnFlowPlacement::InFlow, 'p')->getMessage());
        self::assertStringContainsString('in a header, footer, background or overlay', ColumnFlows::misplaced(ColumnFlowPlacement::OutsideBody, 'p')->getMessage());
    }

    public function test_stray_break_message(): void
    {
        self::assertSame(
            'A ColumnBreak only works inside a ColumnFlow (at "page > body > column-break[0]").',
            ColumnFlows::strayBreak('page > body > column-break[0]')->getMessage(),
        );
    }

    public function test_visit_ancestors_run_from_region_root_to_parent(): void
    {
        $inner = Text::make('deep');
        $doc = Document::make(Page::a4()->body(Column::make(Row::make($inner))));
        $visitor = new class implements TreeVisitor {
            /** @var array<string, list<string>> */
            public array $seen = [];

            public function enterPage(Page $page, int $index): void
            {
            }

            public function leavePage(Page $page, int $index): void
            {
            }

            public function enterRegion(Region $region, ComputedStyle $root): void
            {
            }

            public function leaveRegion(Region $region): void
            {
            }

            public function enter(Node $node, ComputedStyle $style, Visit $at): bool
            {
                $this->seen[$at->path()] = array_map(
                    fn (array $a): string => $a['node']->debugName() . ':' . $a['style']->direction->value,
                    $at->ancestors(),
                );
                return true;
            }

            public function leave(Node $node, ComputedStyle $style, Visit $at): void
            {
            }
        };
        (new TreeWalker(new StyleResolver(Theme::default())))->walk($doc, $visitor);

        self::assertSame([], $visitor->seen['page > body > column[0]']);
        self::assertSame(['column:column'], $visitor->seen['page > body > column[0] > row[0]']);
        self::assertSame(['column:column', 'row:row'], $visitor->seen['page > body > column[0] > row[0] > text[0]']);
    }

    /** @return array<string, array{Region, list<string>, list<string>}> where the probe was laid out, by region it was put in */
    private function probed(Page $page): array
    {
        $seen = new \ArrayObject();
        [$ctx, $engine] = $this->engineWithProbe($seen);
        (new Paginator($engine, $ctx))->paginate(Document::make($page));
        return $seen->getArrayCopy();
    }

    /**
     * @param \ArrayObject<string, array{Region, list<string>, list<string>}> $seen
     * @return array{LayoutContext, LayoutEngine}
     */
    private function engineWithProbe(\ArrayObject $seen): array
    {
        $b = new FakeBackend();
        $ctx = new LayoutContext(FontRegistry::withDefaults($b), Theme::default(), $b, OverflowMode::Throw, null);
        $engine = new LayoutEngine($ctx);
        $engine->register(RegionProbe::class, new class ($ctx, $seen) implements Layouter {
            /** @param \ArrayObject<string, array{Region, list<string>, list<string>}> $seen */
            public function __construct(private readonly LayoutContext $ctx, private readonly \ArrayObject $seen)
            {
            }

            public function layout(\Papercut\Node $node, ComputedStyle $style, Constraints $c, ?BreakToken $token, LayoutEngine $engine): LayoutResult
            {
                \assert($node instanceof RegionProbe);
                $this->seen[$node->label] = [
                    $this->ctx->region,
                    array_map(fn (array $a): string => $a['node']->debugName(), $this->ctx->ancestors()),
                    array_map(fn (array $a): string => $a['node']->debugName(), $this->ctx->lineage()),
                ];
                return LayoutResult::complete(new Fragment($c->width, 1.0));
            }

            public function intrinsic(\Papercut\Node $node, ComputedStyle $style, LayoutEngine $engine): array
            {
                return ['min' => 0.0, 'max' => 0.0];
            }
        });
        return [$ctx, $engine];
    }

    public function test_layout_reports_the_region_and_ancestors_without_the_region_root(): void
    {
        $seen = $this->probed(
            Page::a4()
                ->header(new RegionProbe('header'))
                ->footer(Column::make(new RegionProbe('footer')))
                ->background(new RegionProbe('background'))
                ->body(new RegionProbe('top'), Column::make(Row::make(new RegionProbe('deep'))))
                ->overlay(\Papercut\Place::at('10pt', '10pt', new RegionProbe('overlay'))),
        );
        self::assertSame([Region::Body, [], ['probe']], $seen['top']);
        self::assertSame([Region::Body, ['column', 'row'], ['column', 'row', 'probe']], $seen['deep']);
        self::assertSame([Region::Header, [], ['probe']], $seen['header']);
        self::assertSame([Region::Footer, ['column'], ['column', 'probe']], $seen['footer']);
        self::assertSame([Region::Background, [], ['probe']], $seen['background']);
        self::assertSame(Region::Overlay, $seen['overlay'][0]);
    }

    public function test_lineage_ends_with_the_node_being_laid_out_and_ancestors_leaves_it_out(): void
    {
        $seen = $this->probed(Page::a4()->body(ColumnFlow::make(new RegionProbe('inflow'))));
        self::assertSame(['column-flow'], $seen['inflow'][1]);
        self::assertSame(['column-flow', 'probe'], $seen['inflow'][2]);
    }

    public function test_a_direct_engine_call_drops_nothing(): void
    {
        /** @var \ArrayObject<string, array{Region, list<string>, list<string>}> $seen */
        $seen = new \ArrayObject();
        [, $engine] = $this->engineWithProbe($seen);
        $padded = View::make(new RegionProbe('direct'))->padding(Length::points(4));
        $engine->layout($padded, ComputedStyle::root(Theme::default()), new Constraints(200.0, null, true));
        self::assertSame([Region::Body, ['view'], ['view', 'probe']], $seen['direct']);
    }
}

final class RegionProbe implements Node
{
    public function __construct(public readonly string $label)
    {
    }

    public function debugName(): string
    {
        return 'probe';
    }

    public function style(): \Papercut\Style\Style
    {
        return new \Papercut\Style\Style();
    }
}
