<?php

declare(strict_types=1);

namespace Papercut\Tests\Style;

use Papercut\{Component, ListItem, ListNode, Table, Text, View};
use Papercut\Markup\Markup;
use Papercut\Style\Theme;
use Papercut\Value\Color;
use Papercut\Tests\Support\LayoutHarness;
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

final class ListSpacingTest extends TestCase
{
    /** Baseline of the run with this text. */
    private function baseline(\Papercut\Node $root, string $text, ?Theme $theme = null): float
    {
        $res = (new LayoutHarness($theme))->layout($root, 400);
        foreach (LayoutHarness::runs($res->fragment) as $r) {
            if ($r['text'] === $text) {
                return $r['baseline'];
            }
        }
        self::fail("No run '$text'.");
    }

    private function gap(\Papercut\Node $root, string $from, string $to, ?Theme $theme = null): float
    {
        return $this->baseline($root, $to, $theme) - $this->baseline($root, $from, $theme);
    }

    private function lineStep(): float
    {
        return $this->gap(View::make(Text::make('X'), Text::make('Y')), 'X', 'Y');
    }

    public function test_paragraph_after_a_list_sits_6pt_lower(): void
    {
        $gap = $this->gap(View::make(ListNode::unordered('A', 'B'), Text::make('After')), 'B', 'After');
        self::assertEqualsWithDelta($this->lineStep() + 6.0, $gap, 0.001);
    }

    public function test_markup_list_with_unwrapped_paragraph_gets_the_space(): void
    {
        $gap = $this->gap(View::make(Markup::html('<ul><li><p>A</p></li></ul><p>After</p>')), 'A', 'After');
        self::assertEqualsWithDelta($this->lineStep() + 6.0, $gap, 0.001);
    }

    public function test_markup_list_ending_in_a_p_does_not_stack(): void
    {
        $gap = $this->gap(View::make(Markup::html('<ul><li><p>A</p><p>B</p></li></ul><p>After</p>')), 'B', 'After');
        self::assertEqualsWithDelta($this->lineStep() + 6.0, $gap, 0.001);
    }

    public function test_list_item_ending_in_a_p_does_not_stack(): void
    {
        $gap = $this->gap(View::make(ListNode::unordered(ListItem::make(Text::p('A'))), Text::make('After')), 'A', 'After');
        self::assertEqualsWithDelta($this->lineStep() + 6.0, $gap, 0.001);
    }

    public function test_trailing_margin_found_through_nested_lists(): void
    {
        $outer = ListNode::unordered(ListItem::make('Top', ListNode::unordered(ListItem::make(Text::p('Deep')))));
        $gap = $this->gap(View::make($outer, Text::make('After')), 'Deep', 'After');
        self::assertEqualsWithDelta($this->lineStep() + 6.0, $gap, 0.001);
    }

    public function test_nested_list_adds_no_space(): void
    {
        $outer = ListNode::unordered(ListItem::make('Top', ListNode::unordered('Inner'), 'More'));
        $gap = $this->gap(View::make($outer), 'Inner', 'More');
        self::assertEqualsWithDelta($this->lineStep(), $gap, 0.001);
    }

    public function test_own_margin_wins(): void
    {
        $list = ListNode::unordered('A', 'B')->margin(pt(0), pt(0), pt(20), pt(0));
        $gap = $this->gap(View::make($list, Text::make('After')), 'B', 'After');
        self::assertEqualsWithDelta($this->lineStep() + 20.0, $gap, 0.001);
    }

    private static function height(\Papercut\Node $n): float
    {
        $f = (new LayoutHarness())->layout($n, 400)->fragment;
        self::assertNotNull($f);
        return $f->height;
    }

    public function test_cell_ending_in_a_list_adds_no_space(): void
    {
        $withList = Table::make()->columns('200pt')->row(View::make(Text::make('P'), ListNode::unordered('A', 'B')));
        $plain = Table::make()->columns('200pt')->row(View::make(Text::make('P'), Text::make('A'), Text::make('B')));
        self::assertEqualsWithDelta(self::height($plain), self::height($withList), 0.001);
    }

    public function test_cell_holding_only_a_list_adds_no_space(): void
    {
        $withList = Table::make()->columns('200pt')->row(ListNode::unordered('A', 'B'));
        $plain = Table::make()->columns('200pt')->row(View::make(Text::make('A'), Text::make('B')));
        self::assertEqualsWithDelta(self::height($plain), self::height($withList), 0.001);
    }

    public function test_cell_with_a_list_then_a_paragraph_keeps_the_space(): void
    {
        $withList = Table::make()->columns('200pt')->row(View::make(ListNode::unordered('A'), Text::make('P')));
        $plain = Table::make()->columns('200pt')->row(View::make(Text::make('A'), Text::make('P')));
        self::assertEqualsWithDelta(self::height($plain) + 6.0, self::height($withList), 0.001);
    }

    public function test_box_ending_in_a_list_adds_no_space(): void
    {
        $withList = View::make(Text::make('P'), ListNode::unordered('A'))->padding(pt(4));
        $plain = View::make(Text::make('P'), Text::make('A'))->padding(pt(4));
        self::assertEqualsWithDelta(self::height($plain), self::height($withList), 0.001);
    }

    public function test_bordered_box_ending_in_a_list_adds_no_space(): void
    {
        $withList = View::make(Text::make('P'), ListNode::unordered('A'))->border(pt(1));
        $plain = View::make(Text::make('P'), Text::make('A'))->border(pt(1));
        self::assertEqualsWithDelta(self::height($plain), self::height($withList), 0.001);
    }

    public function test_box_with_a_background_ending_in_a_list_adds_no_space(): void
    {
        $withList = View::make(Text::make('P'), ListNode::unordered('A'))->background(Color::hex('#eeeeee'));
        $plain = View::make(Text::make('P'), Text::make('A'))->background(Color::hex('#eeeeee'));
        self::assertEqualsWithDelta(self::height($plain), self::height($withList), 0.001);
    }

    public function test_transparent_wrapper_ending_in_a_list_keeps_the_space(): void
    {
        $gap = $this->gap(View::make(View::make(ListNode::unordered('A')), Text::make('After')), 'A', 'After');
        self::assertEqualsWithDelta($this->lineStep() + 6.0, $gap, 0.001);
    }

    public function test_markup_list_in_a_wrapper_keeps_the_space(): void
    {
        $gap = $this->gap(View::make(Markup::html('<ul><li>A</li></ul>'), Text::make('After')), 'A', 'After');
        self::assertEqualsWithDelta($this->lineStep() + 6.0, $gap, 0.001);
    }

    public function test_section_ending_in_a_list_keeps_the_space(): void
    {
        $gap = $this->gap(View::make(\Papercut\Decorator\Section::make(ListNode::unordered('A')), Text::make('After')), 'A', 'After');
        self::assertEqualsWithDelta($this->lineStep() + 6.0, $gap, 0.001);
    }

    public function test_decorator_box_ending_in_a_list_adds_no_space(): void
    {
        $withList = \Papercut\Decorator\Card::make(Text::make('P'), ListNode::unordered('A'));
        $plain = \Papercut\Decorator\Card::make(Text::make('P'), Text::make('A'));
        self::assertEqualsWithDelta(self::height(View::make($plain)), self::height(View::make($withList)), 0.001);
    }

    public function test_markup_cell_ending_in_a_list_adds_no_space(): void
    {
        $withList = Markup::html('<table><tr><td><ul><li>a</li></ul></td></tr></table>');
        $plain = Markup::html('<table><tr><td>a</td></tr></table>');
        self::assertEqualsWithDelta(self::height(View::make($plain)), self::height(View::make($withList)), 0.001);
    }

    public function test_list_spacing_follows_the_theme(): void
    {
        $theme = Theme::default();
        $space = $theme->list()->margin?->bottom->points ?? -1.0;
        self::assertSame($theme->paragraph()->margin?->bottom->points, $space);
        $gap = $this->gap(View::make(ListNode::unordered('A'), Text::make('After')), 'A', 'After', $theme);
        self::assertEqualsWithDelta($this->lineStep() + $space, $gap, 0.001);
    }

    public function test_a_component_ending_a_list_item_renders_once(): void
    {
        $renders = 0;
        $component = new class ($renders) implements Component {
            public function __construct(private int &$renders)
            {
            }

            public function render(): \Papercut\Node
            {
                $this->renders++;

                return Text::make('Rendered');
            }
        };
        $this->baseline(View::make(ListNode::unordered(ListItem::make('Top', $component)), Text::make('After')), 'After');

        self::assertSame(1, $renders);
    }

    public function test_a_transparent_background_is_not_a_visible_edge(): void
    {
        $list = ListNode::unordered('A', 'B');
        $plain = $this->gap(View::make(View::make($list)->padding(pt(0)), Text::make('After')), 'B', 'After');
        $transparent = $this->gap(View::make(View::make($list)->background(Color::transparent()), Text::make('After')), 'B', 'After');
        $visible = $this->gap(View::make(View::make($list)->background(Color::hex('#eee')), Text::make('After')), 'B', 'After');

        self::assertEqualsWithDelta($this->lineStep() + 6.0, $transparent, 0.001, 'spacing kept');
        self::assertEqualsWithDelta($plain, $transparent, 0.001);
        self::assertEqualsWithDelta($this->lineStep(), $visible, 0.001, 'spacing dropped at a visible edge');
    }
}
