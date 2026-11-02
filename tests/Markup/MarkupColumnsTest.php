<?php

declare(strict_types=1);

namespace Papercut\Tests\Markup;

use Papercut\{Document, Node, Page, Text, View};
use Papercut\Export\FidelityLevel;
use Papercut\Layout\{LayoutContext, LayoutEngine, Paginator};
use Papercut\Markup\{Markup, MarkupOptions, StyleMode};
use Papercut\Paint\{Painter, TextRun};
use Papercut\Style\Theme;
use Papercut\Value\ColumnSpec;
use Papercut\Tests\Support\FakeBackend;
use Papercut\Text\FontRegistry;
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

final class MarkupColumnsTest extends TestCase
{
    private static function original(string $html): View
    {
        $v = Markup::html($html, new MarkupOptions(styles: StyleMode::Original));
        self::assertInstanceOf(View::class, $v);
        return $v;
    }

    private static function spec(View $v): ColumnSpec
    {
        $spec = self::flow($v)?->columnSpec();
        self::assertNotNull($spec);
        return $spec;
    }

    /** The first View with a column spec, outermost first. */
    private static function flow(View $node): ?View
    {
        if ($node->columnSpec() !== null) {
            return $node;
        }
        foreach ($node->children() as $child) {
            if ($child instanceof View && ($found = self::flow($child)) !== null) {
                return $found;
            }
        }
        return null;
    }

    /** @return list<array{FidelityLevel, string}> */
    private static function notes(View $node): array
    {
        return array_map(static fn ($n) => [$n->level, $n->feature], $node->notes());
    }

    public function test_div_with_column_count_becomes_a_markup_flow(): void
    {
        $v = self::original('<div style="column-count: 3"><p>one</p><p>two</p></div>');
        $flow = self::flow($v);
        self::assertNotNull($flow);
        $spec = self::spec($v);
        self::assertSame(3, $spec->count);
        self::assertTrue($spec->fromMarkup);
        self::assertSame([36.0, 36.0], $spec->gaps);
        self::assertNull($spec->rule);
        self::assertCount(2, $flow->children());
        self::assertSame([], self::notes($v));
    }

    public function test_columns_shorthand_with_width_keeps_count_and_notes_the_width(): void
    {
        $v = self::original('<div style="columns: 2 200px"><p>one</p></div>');
        self::assertSame(2, self::spec($v)->count);
        self::assertSame([[FidelityLevel::Dropped, 'markup CSS "columns: 2 200px" (column width part)']], self::notes($v));
        self::assertSame(4, self::spec(self::original('<div style="columns: 4">x</div>'))->count);
    }

    public function test_column_gap_and_rule_are_read(): void
    {
        $v = self::original('<div style="column-count: 2; column-gap: 24pt; column-rule: 2pt solid #ff0000"><p>x</p></div>');
        $spec = self::spec($v);
        self::assertNotNull($spec->rule);
        self::assertSame([24.0], $spec->gaps);
        self::assertSame(2.0, $spec->rule->width);
        self::assertSame('#ff0000', (string) $spec->rule->color);
        self::assertSame([], self::notes($v));

        $order = self::spec(self::original('<div style="column-count: 2; column-rule: #00ff00 1pt solid; column-gap: normal"><p>x</p></div>'));
        self::assertNotNull($order->rule);
        self::assertSame([36.0], $order->gaps);
        self::assertSame(1.0, $order->rule->width);
        self::assertSame('#00ff00', (string) $order->rule->color);

        $none = self::spec(self::original('<div style="column-count: 2; column-rule: none"><p>x</p></div>'));
        self::assertNull($none->rule);
    }

    public function test_dashed_rule_is_approximated_as_solid(): void
    {
        $v = self::original('<div style="column-count: 2; column-rule: 1pt dashed #000000"><p>x</p></div>');
        self::assertNotNull(self::spec($v)->rule);
        self::assertSame([[FidelityLevel::Approximated, 'markup CSS "column-rule: 1pt dashed #000000" (dashed rule drawn solid)']], self::notes($v));
    }

    public function test_column_span_is_dropped_with_a_note(): void
    {
        $v = self::original('<div style="column-count: 2; column-span: all; column-width: 10pt; column-fill: auto; column-rule-color: red"><p>x</p></div>');
        self::assertSame(2, self::spec($v)->count);
        self::assertSame([
            [FidelityLevel::Dropped, 'markup CSS "column-span: all"'],
            [FidelityLevel::Dropped, 'markup CSS "column-width: 10pt"'],
            [FidelityLevel::Dropped, 'markup CSS "column-fill: auto"'],
            [FidelityLevel::Dropped, 'markup CSS "column-rule-color: red"'],
        ], self::notes($v));
    }

    public function test_theme_mode_ignores_column_css(): void
    {
        $v = Markup::html('<div style="column-count: 2"><p>one</p><p>two</p></div>');
        self::assertInstanceOf(View::class, $v);
        self::assertNull(self::flow($v));
        self::assertSame([], self::notes($v));
    }

    public function test_box_styles_on_a_columns_div_go_on_a_view_around_the_flow(): void
    {
        $v = self::original('<div style="column-count: 2; background-color: #eeeeee; padding: 8pt; width: 300pt"><p>x</p></div>');
        $flow = self::flow($v);
        self::assertNotNull($flow);
        $outer = $v->children()[0];
        self::assertInstanceOf(View::class, $outer);
        self::assertNotSame($flow, $outer, 'box styles sit on an outer view');
        self::assertNull($flow->style()->background);
        self::assertNull($flow->style()->padding);
        self::assertNull($flow->style()->width);
        self::assertSame('#eeeeee', (string) $outer->style()->background);
        self::assertNull($outer->columnSpec());
    }

    public function test_huge_column_counts_are_clamped_with_a_note(): void
    {
        foreach (['99999999999999999999', '2000000000', '46'] as $n) {
            $v = self::original('<div style="column-count: ' . $n . '"><p>x</p></div>');
            self::assertSame(45, self::spec($v)->count, $n);
            self::assertSame([[FidelityLevel::Approximated, 'markup CSS "column-count: ' . $n . '" (column count limited to 45)']], self::notes($v));
        }
        self::assertSame(45, self::spec(self::original('<div style="column-count: 45"><p>x</p></div>'))->count);
    }

    public function test_partial_notes_are_not_made_when_the_column_css_is_dropped(): void
    {
        $v = self::original('<p style="columns: 2 200px">x</p><p style="column-rule: 1pt dashed red">y</p>');
        self::assertSame([
            [FidelityLevel::Dropped, 'markup CSS "columns: 2 200px"'],
            [FidelityLevel::Dropped, 'markup CSS "column-rule: 1pt dashed red"'],
        ], self::notes($v));
    }

    public function test_builder_rejects_more_than_the_maximum_columns(): void
    {
        $this->expectException(\Papercut\Exception\InvalidValue::class);
        View::make()->columns(46);
    }

    public function test_column_count_on_a_paragraph_is_dropped(): void
    {
        $v = self::original('<p style="column-count: 2">x</p><h2 style="columns: 2">y</h2><ul style="column-count: 2"><li>z</li></ul>');
        self::assertNull(self::flow($v));
        self::assertSame([
            [FidelityLevel::Dropped, 'markup CSS "column-count: 2"'],
            [FidelityLevel::Dropped, 'markup CSS "columns: 2"'],
            [FidelityLevel::Dropped, 'markup CSS "column-count: 2"'],
        ], self::notes($v));
    }

    public function test_gap_without_a_count_is_dropped(): void
    {
        $v = self::original('<div style="column-gap: 10pt"><p>x</p><p>y</p></div>');
        self::assertNull(self::flow($v));
        self::assertSame([[FidelityLevel::Dropped, 'markup CSS "column-gap: 10pt"']], self::notes($v));
    }

    public function test_markup_flow_renders_in_two_columns(): void
    {
        $html = '<div style="column-count: 2; column-gap: 20pt">' . implode('', array_map(static fn (int $i) => "<p>L$i</p>", range(1, 8))) . '</div>';
        $page = Page::size(pt(220), pt(100))->margin(pt(10))->headerGap(pt(0))->footerGap(pt(0));
        $b = new FakeBackend();
        $ctx = new LayoutContext(FontRegistry::withDefaults($b), Theme::default(), $b, \Papercut\OverflowMode::Throw, static function (string $w): void {});
        $pages = Painter::paint((new Paginator(new LayoutEngine($ctx), $ctx))->paginate(Document::make($page->body(self::original($html)))));
        $xs = [];
        foreach ($pages[0]->ops as $o) {
            if ($o instanceof TextRun) {
                $xs[(string) round($o->x, 3)] = true;
            }
        }
        self::assertCount(2, $xs, 'two distinct x positions: ' . implode(', ', array_keys($xs)));
    }

    public function test_non_positive_rule_width_and_negative_gap_are_dropped_not_thrown(): void
    {
        $v = self::original('<div style="column-count: 2; column-rule: -2pt solid red"><p>x</p></div>');
        self::assertNull(self::spec($v)->rule);
        self::assertSame([[FidelityLevel::Dropped, 'markup CSS "column-rule: -2pt solid red"']], self::notes($v));
        $v = self::original('<div style="column-count: 2; column-rule: 0pt solid red"><p>x</p></div>');
        self::assertNull(self::spec($v)->rule);
        $v = self::original('<div style="column-count: 2; column-gap: -4pt"><p>x</p></div>');
        self::assertSame([36.0], self::spec($v)->gaps);
    }
}
