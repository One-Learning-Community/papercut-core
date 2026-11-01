<?php

declare(strict_types=1);

namespace Papercut\Tests\Layout;

use Papercut\{Column, Document, ListNode, Node, OverflowMode, Page, Row, Text};
use Papercut\Exception\LayoutOverflow;
use Papercut\Layout\{LayoutContext, LayoutEngine, Paginator};
use Papercut\Paint\{Clip, PageDisplayList, Painter, TextRun};
use Papercut\Style\Theme;
use Papercut\Tests\Support\FakeBackend;
use Papercut\Text\FontRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

/** Content taller than a box's fixed height or max-height follows the overflow mode instead of drawing over what follows. */
final class FixedHeightOverflowTest extends TestCase
{
    /** @var list<string> */
    private array $warnings = [];

    /** @return list<PageDisplayList> */
    private function paginate(Node $box, OverflowMode $mode): array
    {
        $b = new FakeBackend();
        $ctx = new LayoutContext(FontRegistry::withDefaults($b), Theme::default(), $b, $mode, function (string $w): void {
            $this->warnings[] = $w;
        });
        $page = Page::size(pt(300), pt(300))->margin(pt(10))->headerGap(pt(0))->footerGap(pt(0));
        $doc = Document::make($page->body($box, Text::make('Next')));

        return Painter::paint((new Paginator(new LayoutEngine($ctx), $ctx))->paginate($doc));
    }

    /**
     * Five lines, about 70pt tall, in a box with room for two.
     *
     * @return list<Text>
     */
    private static function lines(): array
    {
        return array_map(static fn (int $i): Text => Text::make("Line $i"), range(1, 5));
    }

    /** @return iterable<string, array{Node, string}> */
    public static function overflowingBoxes(): iterable
    {
        yield 'column with a fixed height' => [Column::make(...self::lines())->height(pt(30)), 'column[0]'];
        yield 'column with a max-height' => [Column::make(...self::lines())->maxHeight(pt(30)), 'column[0]'];
        yield 'row with a fixed height' => [Row::make(Column::make(...self::lines()))->height(pt(30)), 'row[0]'];
        yield 'row with a max-height' => [Row::make(Column::make(...self::lines()))->maxHeight(pt(30)), 'row[0]'];
        yield 'text with a fixed height' => [Text::markup("Line 1<br>Line 2<br>Line 3<br>Line 4<br>Line 5")->height(pt(30)), 'text[0]'];
        yield 'text with a max-height' => [Text::markup("Line 1<br>Line 2<br>Line 3<br>Line 4<br>Line 5")->maxHeight(pt(30)), 'text[0]'];
    }

    #[DataProvider('overflowingBoxes')]
    public function test_throw_mode_throws_naming_the_box(Node $box, string $segment): void
    {
        try {
            $this->paginate($box, OverflowMode::Throw);
            self::fail('expected LayoutOverflow');
        } catch (LayoutOverflow $e) {
            self::assertSame('page > body > ' . $segment, $e->path);
            self::assertStringContainsString('taller than its height or max-height allows', $e->getMessage());
        }
    }

    #[DataProvider('overflowingBoxes')]
    public function test_clip_mode_clips_to_the_box_and_warns(Node $box, string $segment): void
    {
        $pages = $this->paginate($box, OverflowMode::Clip);
        self::assertCount(1, $pages);
        $clips = array_values(array_filter($pages[0]->ops, static fn ($o) => $o instanceof Clip));
        self::assertCount(1, $clips);
        self::assertEqualsWithDelta([10.0, 10.0, 280.0, 30.0], [$clips[0]->x, $clips[0]->y, $clips[0]->width, $clips[0]->height], 1e-6);
        self::assertCount(1, $this->warnings);
        self::assertStringContainsString('page > body > ' . $segment, $this->warnings[0]);
        self::assertStringContainsString('taller than its height or max-height allows', $this->warnings[0]);
        // What follows the box starts below it, outside the clip.
        $next = array_values(array_filter($pages[0]->ops, static fn ($o) => $o instanceof TextRun && $o->text === 'Next'));
        self::assertCount(1, $next);
    }

    /** @return iterable<string, array{Node}> */
    public static function boxesWhoseLastMarginSticksOut(): iterable
    {
        // A paragraph is 14pt tall with 6pt of margin below it: the margin is space, not content.
        yield 'paragraph in a column' => [Column::make(Text::p('A'))->height(pt(16))];
        yield 'paragraphs in a row' => [Row::make(Text::p('A'), Text::p('B'))->height(pt(16))];
        yield 'list in a column' => [Column::make(ListNode::unordered('a'))->height(pt(16))];
        yield 'heading in a column' => [Column::make(Text::h2('Title'))->height(pt(26))];
    }

    #[DataProvider('boxesWhoseLastMarginSticksOut')]
    public function test_the_last_childs_bottom_margin_is_not_overflow(Node $box): void
    {
        $pages = $this->paginate($box, OverflowMode::Throw);
        self::assertSame([], array_values(array_filter($pages[0]->ops, static fn ($o) => $o instanceof Clip)));
        self::assertSame([], $this->warnings);
    }

    public function test_content_that_fits_its_height_is_left_alone(): void
    {
        $pages = $this->paginate(Column::make(Text::make('One line'))->height(pt(30))->border(pt(1)), OverflowMode::Throw);
        self::assertSame([], array_values(array_filter($pages[0]->ops, static fn ($o) => $o instanceof Clip)));
        self::assertSame([], $this->warnings);
    }
}
