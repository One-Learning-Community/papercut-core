<?php

declare(strict_types=1);

namespace Papercut\Tests\Layout;

use Papercut\{Document, ListItem, ListNode, Page, Table, TableCell, Text, Toc};
use Papercut\Export\PdfOptions;
use Papercut\Layout\{Fragment, LayoutContext, LayoutEngine, Paginator, PlacedPage};
use Papercut\OverflowMode;
use Papercut\Style\{PageThemes, Style, TextAlign, Theme};
use Papercut\Text\FontRegistry;
use Papercut\Toc\TocIndex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Measure-only layout (Word's table of contents page numbers) breaks lines and pages exactly like a full layout. */
final class MeasureOnlyLayoutTest extends TestCase
{
    /** @return iterable<string, array{\Closure(): Document}> */
    public static function documents(): iterable
    {
        foreach (glob(__DIR__ . '/../Visual/fixtures/*.php') ?: [] as $file) {
            yield basename($file, '.php') => [static fn (): Document => require $file];
        }
        yield 'mixed long document' => [static function (): Document {
            $body = [Toc::make()->levels(1, 3)->title('Contents')];
            for ($i = 1; $i <= 12; $i++) {
                $body[] = Text::h1("Chapter $i with a title long enough to wrap onto a second line in the contents");
                $body[] = Text::p(str_repeat("Justified text $i, with some kerning: AVATAR Wave To. ", 14))->apply(Style::make(textAlign: TextAlign::Justify));
                $body[] = Text::markup('Mixed <b>bold</b> and <i>italic</i> runs, <u>underlined</u>' . str_repeat(' then more words', 9));
                $body[] = Text::p(str_repeat('Right aligned. ', 10))->apply(Style::make(textAlign: TextAlign::Right));
                $body[] = ListNode::ordered(...array_map(static fn (int $n): ListItem => ListItem::make(Text::make(str_repeat("Item $n ", 9))), range(1, 6)));
                $body[] = Text::h2("Section $i.1");
                $table = Table::make();
                foreach (range(1, 6) as $r) {
                    $table = $table->row(TableCell::make("Row $r"), str_repeat('Cell text ', 5));
                }
                $body[] = $table;
                $body[] = Text::h3("Detail $i.1.1");
            }
            return Document::make(Page::a5()->body(...$body));
        }];
    }

    #[DataProvider('documents')]
    public function test_pages_and_positions_match_a_full_layout(\Closure $make): void
    {
        $doc = $make();
        $full = self::paginate($doc, false);
        $measured = self::paginate($doc, true);

        self::assertSame(self::shape($full), self::shape($measured));
    }

    /** @return list<PlacedPage> */
    private static function paginate(Document $doc, bool $measureOnly): array
    {
        $options = new PdfOptions();
        $backend = $options->backend();
        $themes = PageThemes::for(Theme::default(), $doc);
        $ctx = new LayoutContext(FontRegistry::fromSet($options->fonts(), $backend), $themes->document(), $backend, OverflowMode::Clip, static function (): void {
        }, $options->fonts()->fallbacks);
        $ctx->themes = $themes;
        $ctx->toc = TocIndex::collect($doc, $themes);
        $ctx->measureOnly = $measureOnly;
        return (new Paginator(new LayoutEngine($ctx), $ctx))->paginate($doc);
    }

    /**
     * Every fragment's box, page by page, rounded to a thousandth of a point.
     *
     * @param list<PlacedPage> $pages
     * @return list<list<string>>
     */
    private static function shape(array $pages): array
    {
        $out = [];
        foreach ($pages as $page) {
            $boxes = [];
            foreach ($page->layers as $layer) {
                self::boxes($layer['fragment'], $layer['x'], $layer['y'], $boxes);
            }
            $out[] = $boxes;
        }
        return $out;
    }

    /** @param list<string> $out */
    private static function boxes(Fragment $f, float $x, float $y, array &$out): void
    {
        $out[] = sprintf('%.3f %.3f %.3f %.3f', $x, $y, $f->width, $f->height);
        foreach ($f->children() as $c) {
            self::boxes($c['fragment'], $x + $c['x'], $y + $c['y'], $out);
        }
    }
}
