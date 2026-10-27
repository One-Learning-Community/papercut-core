<?php

declare(strict_types=1);

namespace Papercut\Tests\Feature;

use Papercut\Backend\{Backend, FontHandle, ImageHandle};
use Papercut\{Column, Document, Page, Text};
use Papercut\Export\PdfOptions;
use Papercut\Paint\{Bookmark, Clip, DocumentMeta, Opacity, Op, PageDisplayList};
use Papercut\Tests\Support\{FakeBackend, PdfInspector};
use PHPUnit\Framework\TestCase;
use function Papercut\{mm, pt};

final class BookmarksTest extends TestCase
{
    /** @return list<array{page: int, level: int, title: string, y: float}> */
    private static function bookmarks(Document $doc, bool $enabled = true): array
    {
        $backend = new class () implements Backend {
            /** @var list<PageDisplayList> */
            public array $pages = [];
            private FakeBackend $fake;

            public function __construct()
            {
                $this->fake = new FakeBackend();
            }

            public function loadFont(string $path): FontHandle
            {
                return $this->fake->loadFont($path);
            }

            public function loadImageFile(string $path): ImageHandle
            {
                return $this->fake->loadImageFile($path);
            }

            public function loadImageBytes(string $bytes): ImageHandle
            {
                return $this->fake->loadImageBytes($bytes);
            }

            public function render(DocumentMeta $meta, array $pages): string
            {
                $this->pages = $pages;
                return '';
            }
        };
        $doc->toPdf(new PdfOptions(backend: $backend, bookmarks: $enabled));

        $found = [];
        $walk = static function (array $ops, int $page) use (&$walk, &$found): void {
            foreach ($ops as $op) {
                if ($op instanceof Bookmark) {
                    $found[] = ['page' => $page, 'level' => $op->level, 'title' => $op->title, 'y' => $op->y];
                } elseif ($op instanceof Clip || $op instanceof Opacity) {
                    $walk($op->ops, $page);
                }
            }
        };
        foreach ($backend->pages as $i => $page) {
            $walk($page->ops, $i + 1);
        }
        return $found;
    }

    /**
     * @param list<array{page: int, level: int, title: string, y: float}> $bookmarks
     * @return list<array{int, int, string}>
     */
    private static function strip(array $bookmarks): array
    {
        return array_map(static fn (array $b): array => [$b['page'], $b['level'], $b['title']], $bookmarks);
    }

    /** @return list<Text> */
    private static function filler(int $paragraphs): array
    {
        return array_map(static fn (int $i): Text => Text::p(str_repeat("Filler $i. ", 80)), range(1, $paragraphs));
    }

    public function test_headings_become_bookmarks_at_their_level(): void
    {
        $doc = Document::make(Page::a4()->body(Column::make(
            Text::h1('Report'),
            Text::h2('Summary'),
            Text::p('Body.'),
            Text::h3('Detail'),
            Text::h4('Fine print'),
        )));

        $this->assertSame([[1, 1, 'Report'], [1, 2, 'Summary'], [1, 3, 'Detail'], [1, 4, 'Fine print']], self::strip(self::bookmarks($doc)));
    }

    public function test_a_bookmark_points_at_its_block_on_the_right_page(): void
    {
        $doc = Document::make(Page::a4()->margin(mm(20))->body(Column::make(...[
            Text::h1('One'),
            ...self::filler(12),
            Text::h1('Two')->breakBefore(),
        ])->gap(pt(6))));

        $marks = self::bookmarks($doc);

        $this->assertSame('One', $marks[0]['title']);
        $this->assertSame('Two', $marks[1]['title']);
        $this->assertSame(1, $marks[0]['page']);
        $this->assertGreaterThan(1, $marks[1]['page']);
        // Both sit at the top of the body, below the 20mm margin.
        $this->assertEqualsWithDelta(mm(20)->points, $marks[1]['y'], 0.01);
    }

    public function test_any_block_can_set_a_bookmark_and_level(): void
    {
        $doc = Document::make(Page::a4()->body(Column::make(
            Column::make(Text::make('Terms'))->bookmark('Terms and conditions'),
            Text::make('Appendix A')->bookmark('Appendix A', 2),
            Text::h2('Renamed')->bookmark('Shorter title'),
            Text::h3('Promoted')->bookmark('Promoted', 1),
        )));

        $this->assertSame([[1, 1, 'Terms and conditions'], [1, 2, 'Appendix A'], [1, 2, 'Shorter title'], [1, 1, 'Promoted']], self::strip(self::bookmarks($doc)));
    }

    public function test_bookmark_false_turns_off_a_heading(): void
    {
        $doc = Document::make(Page::a4()->body(Column::make(Text::h1('Kept'), Text::h2('Hidden')->bookmark(false))));

        $this->assertSame([[1, 1, 'Kept']], self::strip(self::bookmarks($doc)));
    }

    public function test_titles_are_plain_text_on_one_line(): void
    {
        $doc = Document::make(Page::a4()->body(Text::markup("<b>Mixed</b>  <i>runs</i><br>here")->withPreset('h1')));

        $this->assertSame([[1, 1, 'Mixed runs here']], self::strip(self::bookmarks($doc)));
    }

    public function test_a_block_split_across_pages_is_bookmarked_once(): void
    {
        $doc = Document::make(Page::a4()->body(Column::make(...self::filler(30))->bookmark('Long section')));

        $marks = self::bookmarks($doc);

        $this->assertSame([[1, 1, 'Long section']], self::strip($marks));
    }

    public function test_headings_in_headers_footers_and_backgrounds_are_ignored(): void
    {
        $doc = Document::make(Page::a4()
            ->header(Text::h2('Running head'))
            ->footer(Text::h3('Running foot'))
            ->background(Text::h1('Watermark'))
            ->body(Column::make(...[Text::h1('Body'), ...self::filler(30)])));

        $this->assertSame([[1, 1, 'Body']], self::strip(self::bookmarks($doc)));
    }

    public function test_bookmarks_can_be_turned_off_for_the_document(): void
    {
        $doc = Document::make(Page::a4()->body(Text::h1('Report')));

        $this->assertSame([], self::bookmarks($doc, enabled: false));
    }

    public function test_the_pdf_has_a_nested_outline(): void
    {
        $doc = Document::make(Page::a4()->margin(mm(20))->body(Column::make(...[
            Text::h1('Introduction'),
            Text::h3('Skipped a level'),
            Text::h2('Background'),
            ...self::filler(12),
            Text::h1('Results')->breakBefore(),
        ])->gap(pt(6))));

        $pdf = $doc->toPdf()->bytes();
        PdfInspector::check($pdf);
        $outline = self::outline($pdf);

        $this->assertSame([
            ['Introduction', 1, [['Skipped a level', 1, []], ['Background', 1, []]]],
            ['Results', (int) PdfInspector::info($pdf)['Pages'], []],
        ], $outline);
    }

    /** @return array<int, mixed> title, page and children for each top-level entry, decoded from qpdf's JSON */
    private static function outline(string $pdf): array
    {
        $file = tempnam(sys_get_temp_dir(), 'pc') . '.pdf'; // qpdf's AppArmor profile wants .pdf
        rename(substr($file, 0, -4), $file);
        file_put_contents($file, $pdf);
        exec(sprintf('qpdf --json --json-key=outlines %s 2>&1', escapeshellarg($file)), $out);
        unlink($file);
        $walk = static function (array $items) use (&$walk): array {
            return array_map(static fn (array $e): array => [$e['title'], $e['destpageposfrom1'], $walk($e['kids'])], $items);
        };
        return $walk(json_decode(implode("\n", $out), true, flags: JSON_THROW_ON_ERROR)['outlines']);
    }
}
