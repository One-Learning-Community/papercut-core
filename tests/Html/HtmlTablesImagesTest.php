<?php

declare(strict_types=1);

namespace Papercut\Tests\Html;

use Papercut\{Column, Document, Image, Link, Page, Spacer, Table, TableCell, TableRow, Text};
use Papercut\Html\Css;
use Papercut\Style\{Direction, Justify};
use Papercut\Exception\ImageUnreadable;
use Papercut\Export\{HtmlOptions, PdfOptions};
use Papercut\Style\ImageFit;
use Papercut\Backend\{Backend, FontHandle, ImageHandle};
use Papercut\Backend\PhpPdf\PhpPdfBackend;
use Papercut\Export\Fidelity;
use Papercut\Html\HtmlWriter;
use Papercut\Paint\DocumentMeta;
use Papercut\Style\StyleResolver;
use Papercut\Tests\Support\TestImages;
use Papercut\Tree\TreeWalker;
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

final class HtmlTablesImagesTest extends TestCase
{
    private function html(Document $doc, ?HtmlOptions $o = null): string
    {
        return $doc->toHtml($o)->bytes();
    }

    private function dom(string $html): \Dom\HTMLDocument
    {
        return \Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><head><title>t</title></head><body>' . $html . '</body></html>');
    }

    /** @return list<\Dom\Element> */
    private function xpath(string $html, string $query): array
    {
        $xp = new \Dom\XPath($this->dom($html));
        $xp->registerNamespace('h', 'http://www.w3.org/1999/xhtml');
        $query = (string) preg_replace('~(?<=/)([a-z][a-z0-9]*)(?![a-z0-9(\-])~', 'h:$1', $query);
        $found = [];
        foreach ($xp->query($query) as $n) {
            self::assertInstanceOf(\Dom\Element::class, $n);
            $found[] = $n;
        }
        return $found;
    }

    private function style(\Dom\Element $e): string
    {
        return (string) $e->getAttribute('style');
    }

    private function body(\Papercut\Node|\Papercut\Component ...$nodes): Document
    {
        return Document::make(Page::a4()->body(...$nodes));
    }

    public function test_table_has_colgroup_widths_thead_and_colspan(): void
    {
        $messages = [];
        $table = Table::make()->columns('30mm 2fr 1fr auto')
            ->head('A', 'B', 'C', 'D')
            ->row(TableCell::make('wide')->colspan(3), 'x')
            ->row('1', '2', '3', '4');
        $html = $this->html($this->body($table), new HtmlOptions(onWarning: function (string $m) use (&$messages): void {
            $messages[] = $m;
        }));
        $cols = $this->xpath($html, '//table/colgroup/col');
        $fixed = 30 * 72 / 25.4;
        $fixedCss = rtrim(rtrim(number_format($fixed, 3, '.', ''), '0'), '.');
        self::assertCount(4, $cols);
        self::assertSame('width:' . $fixedCss . 'pt', $this->style($cols[0]));
        self::assertSame('', $this->style($cols[1]));
        self::assertSame('', $this->style($cols[2]));
        self::assertSame('', $this->style($cols[3]));
        self::assertStringContainsString('table-layout:auto', $this->style($this->xpath($html, '//table')[0]));
        self::assertCount(1, $this->xpath($html, '//table/thead/tr'));
        self::assertCount(2, $this->xpath($html, '//table/tbody/tr'));
        $wide = $this->xpath($html, '//tbody/tr[1]/td[1]');
        self::assertSame('3', $wide[0]->getAttribute('colspan'));
        self::assertNull($this->xpath($html, '//tbody/tr[1]/td[2]')[0]->getAttribute('colspan'));
        self::assertCount(2, array_filter($messages, static fn (string $m): bool => str_contains($m, 'column widths')));
        self::assertContains('html: approximated fr column widths at page > body > table[0]', $messages);
        self::assertContains('html: approximated auto column widths at page > body > table[0]', $messages);
    }

    public function test_fill_width_table_is_full_width(): void
    {
        $html = $this->html($this->body(Table::make()->columns('auto auto')->fillWidth()->row('a', 'b')));
        $style = $this->style($this->xpath($html, '//table')[0]);
        self::assertStringContainsString('width:100%', $style);
        self::assertStringContainsString('table-layout:auto', $style);
        $plain = $this->html($this->body(Table::make()->columns('auto auto')->row('a', 'b')));
        self::assertStringNotContainsString('width:100%', $this->style($this->xpath($plain, '//table')[0]));
    }

    public function test_fit_columns_emits_length_columns_scaled_to_the_width(): void
    {
        $widths = function (Table $t): array {
            $out = [];
            foreach ($this->xpath($this->html($this->body($t)), '//col') as $col) {
                $css = $this->style($col);
                self::assertStringStartsWith('width:', $css);
                self::assertStringEndsWith('pt', $css);
                $out[] = (float) substr($css, 6, -2);
            }
            return $out;
        };
        $plain = $widths(Table::make()->columns('400pt 400pt')->row('a', 'b'));
        self::assertSame([400.0, 400.0], $plain);
        $fitted = $widths(Table::make()->columns('400pt 400pt')->fitColumns()->row('a', 'b'));
        self::assertEqualsWithDelta($this->contentWidth(), array_sum($fitted), 0.01);
        self::assertEqualsWithDelta($fitted[0], $fitted[1], 0.001);
        $fits = $widths(Table::make()->columns('100pt 100pt')->fitColumns()->row('a', 'b'));
        self::assertSame([100.0, 100.0], $fits);
    }

    public function test_rowspan_attribute(): void
    {
        $html = $this->html($this->body(Table::make()->columns('1fr 1fr')->row(TableCell::make('a')->rowspan(2), 'b')->row('c')));
        self::assertStringContainsString('<td rowspan="2"', $html);
        self::assertCount(2, $this->xpath($html, '//table/colgroup/col'));
    }

    public function test_colspan_running_into_a_rowspan_is_clamped(): void
    {
        $html = $this->html($this->body(Table::make()->columns('1fr 1fr 1fr')->row('a', 'b', TableCell::make('c')->rowspan(2))->row(TableCell::make('d')->colspan(3))));
        self::assertStringContainsString('colspan="2"', $html);
        self::assertStringNotContainsString('colspan="3"', $html);
    }

    public function test_explicit_width_wins_over_fill_width(): void
    {
        $html = $this->html($this->body(Table::make()->columns('auto auto')->fillWidth()->width(pt(300))->row('a', 'b')));
        $style = $this->style($this->xpath($html, '//table')[0]);
        self::assertStringContainsString('width:300pt', $style);
        self::assertStringNotContainsString('width:100%', $style);
    }

    public function test_missing_tracks_are_fr_and_extra_ignored(): void
    {
        $html = $this->html($this->body(Table::make()->columns('10pt 20pt 30pt 40pt')->row('a', 'b')));
        self::assertCount(2, $this->xpath($html, '//colgroup/col'));
        $html = $this->html($this->body(Table::make()->columns('10pt')->row('a', 'b')));
        $cols = $this->xpath($html, '//colgroup/col');
        self::assertSame('width:' . Css::num(100 - 10 / $this->contentWidth() * 100) . '%', $this->style($cols[1]));
    }

    public function test_header_row_cells_are_th_and_bold_from_theme(): void
    {
        $html = $this->html($this->body(Table::make()->columns('1fr 1fr')->head('H1', 'H2')->row('a', 'b')));
        $th = $this->xpath($html, '//thead/tr/th');
        self::assertCount(2, $th);
        self::assertStringContainsString('font-weight:700', $this->style($th[0]));
        self::assertStringContainsString('padding:', $this->style($th[0]));
        $td = $this->xpath($html, '//tbody/tr/td');
        self::assertCount(2, $td);
        self::assertStringNotContainsString('font-weight', $this->style($td[0]));
        self::assertCount(0, $this->xpath($html, '//thead/tr/td'));
    }

    public function test_cell_children_render_as_blocks_and_tables_nest(): void
    {
        $inner = Table::make()->columns('1fr')->row('deep');
        $html = $this->html($this->body(Table::make()->columns('1fr 1fr')->row(Column::make(Text::make('one'), Text::make('two')), $inner)));
        self::assertCount(2, $this->xpath($html, '//td[1]/div/div/p'));
        self::assertCount(1, $this->xpath($html, '//td[2]/div/table/tbody/tr/td'));
        self::assertCount(1, $this->xpath($html, '//table//table'));
    }

    public function test_row_style_is_emitted(): void
    {
        $html = $this->html($this->body(Table::make()->columns('1fr')->bodyRow(TableRow::make('a')->background(\Papercut\Value\Color::hex('#ff0000')))));
        self::assertStringContainsString('background:#ff0000', $this->style($this->xpath($html, '//tbody/tr')[0]));
    }

    public function test_png_and_jpeg_embed_as_data_uris(): void
    {
        $png = TestImages::png(8, 4);
        $im = imagecreatetruecolor(8, 4);
        self::assertNotFalse($im);
        ob_start();
        imagejpeg($im);
        $jpeg = (string) ob_get_clean();
        $html = $this->html($this->body(Image::bytes($png), Image::bytes($jpeg)));
        $imgs = $this->xpath($html, '//div[@class="pc-body"]/img');
        self::assertCount(2, $imgs);
        self::assertSame('data:image/png;base64,' . base64_encode($png), $imgs[0]->getAttribute('src'));
        self::assertSame('data:image/jpeg;base64,' . base64_encode($jpeg), $imgs[1]->getAttribute('src'));
        self::assertSame('', $imgs[0]->getAttribute('alt'));
    }

    public function test_file_image_is_read_and_embedded(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pc') ?: throw new \RuntimeException('tmp');
        $png = TestImages::png();
        file_put_contents($path, $png);
        try {
            $html = $this->html($this->body(Image::file($path)));
        } finally {
            unlink($path);
        }
        self::assertSame('data:image/png;base64,' . base64_encode($png), $this->xpath($html, '//img')[0]->getAttribute('src'));
    }

    public function test_image_url_callback_wins(): void
    {
        $opts = new HtmlOptions(imageUrl: static fn (Image $i): string => 'https://cdn.example/a.png?x=1&y="2"');
        $html = $this->html($this->body(Image::file('/nonexistent/never-read.png')), $opts);
        self::assertStringContainsString('src="https://cdn.example/a.png?x=1&amp;y=&quot;2&quot;"', $html);
        self::assertSame('https://cdn.example/a.png?x=1&y="2"', $this->xpath($html, '//img')[0]->getAttribute('src'));

        foreach (['img/a.png', 'data:image/gif;base64,R0lGOD'] as $ok) {
            $html = $this->html($this->body(Image::bytes(TestImages::png())), new HtmlOptions(imageUrl: static fn (Image $i): string => $ok));
            self::assertSame($ok, $this->xpath($html, '//img')[0]->getAttribute('src'));
        }
    }

    public function test_null_or_unsafe_callback_result_embeds(): void
    {
        $png = TestImages::png();
        foreach ([null, 'javascript:alert(1)', "java\tscript:alert(1)", 'data:text/html,<script>', '//evil.example/x.png', 'file:///etc/passwd'] as $bad) {
            $html = $this->html($this->body(Image::bytes($png)), new HtmlOptions(imageUrl: static fn (Image $i): ?string => $bad));
            self::assertStringStartsWith('data:image/png;base64,', (string) $this->xpath($html, '//img')[0]->getAttribute('src'), (string) $bad);
        }
    }

    public function test_missing_and_unsupported_images_throw_image_unreadable(): void
    {
        $cases = [
            [Image::file('/no/such/file.png'), 'Cannot read image "/no/such/file.png": file not found or not readable'],
            [Image::bytes('GIF89a....'), 'Cannot read image bytes: Invalid GIF image data.'],
            [Image::bytes(''), 'Cannot read image bytes: Unsupported image format (expected PNG, JPEG, WebP, GIF, BMP or AVIF).'],
        ];
        foreach ($cases as [$image, $message]) {
            try {
                $this->html($this->body($image));
                self::fail('expected ImageUnreadable');
            } catch (ImageUnreadable $e) {
                self::assertSame($message, $e->getMessage());
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'pc') ?: throw new \RuntimeException('tmp');
        file_put_contents($path, 'not an image');
        try {
            $this->html($this->body(Image::file($path)));
            self::fail('expected ImageUnreadable');
        } catch (ImageUnreadable $e) {
            self::assertSame(sprintf('Cannot read image "%s": Unsupported image format (expected PNG, JPEG, WebP, GIF, BMP or AVIF).', $path), $e->getMessage());
        } finally {
            unlink($path);
        }
    }

    public function test_object_fit_maps_fit_mode(): void
    {
        $png = TestImages::png();
        $html = $this->html($this->body(
            Image::bytes($png),
            Image::bytes($png)->fit(ImageFit::Cover),
            Image::bytes($png)->fit(ImageFit::Fill)->width(pt(50)),
        ));
        $imgs = $this->xpath($html, '//img');
        self::assertStringContainsString('object-fit:contain', $this->style($imgs[0]));
        self::assertStringContainsString('object-fit:cover', $this->style($imgs[1]));
        self::assertStringContainsString('object-fit:fill', $this->style($imgs[2]));
        self::assertStringContainsString('width:50pt', $this->style($imgs[2]));
    }

    public function test_image_inside_cell_gets_flex_item_declarations_from_the_wrapper(): void
    {
        $html = $this->html($this->body(Table::make()->columns('1fr')->row(Image::bytes(TestImages::png())->grow(1))));
        self::assertStringContainsString('flex:1 1 auto', $this->style($this->xpath($html, '//td/div/img')[0]));
    }

    public function test_stray_cell_renders_as_div_and_warns_like_pdf(): void
    {
        $doc = $this->body(Column::make(TableCell::make(Text::make('lonely'))->padding(pt(3)), Text::make('after')));
        $htmlWarnings = [];
        $html = $this->html($doc, new HtmlOptions(onWarning: function (string $m) use (&$htmlWarnings): void {
            $htmlWarnings[] = $m;
        }));
        $pdfWarnings = [];
        $doc->toPdf(new PdfOptions(onWarning: function (string $m) use (&$pdfWarnings): void {
            $pdfWarnings[] = $m;
        }));
        $strayPdf = array_values(array_filter($pdfWarnings, static fn (string $m): bool => str_contains($m, 'outside a table')));
        $strayHtml = array_values(array_filter($htmlWarnings, static fn (string $m): bool => str_contains($m, 'outside a table')));
        self::assertCount(1, $strayPdf);
        self::assertSame($strayPdf, $strayHtml);
        self::assertMatchesRegularExpression('/^\[.+\] A table cell outside a table is laid out as a column$/', $strayHtml[0]);

        $div = $this->xpath($html, '//div[@class="pc-body"]/div/div')[0];
        self::assertStringContainsString('display:flex', $this->style($div));
        self::assertStringContainsString('flex-direction:column', $this->style($div));
        self::assertStringContainsString('padding:3pt', $this->style($div));
        self::assertCount(0, $this->xpath($html, '//td | //th'));
        self::assertSame('lonely', $this->xpath($html, '//div[@class="pc-body"]/div/div/p')[0]->textContent);
    }

    public function test_stray_cell_warning_respects_two_argument_callbacks(): void
    {
        $got = [];
        $this->html($this->body(TableCell::make('x')), new HtmlOptions(onWarning: function (string $m, ?\Papercut\Export\FidelityReport $r = null) use (&$got): void {
            $got[] = [$m, $r];
        }));
        $stray = array_values(array_filter($got, static fn (array $g): bool => str_contains($g[0], 'outside a table')));
        self::assertCount(1, $stray);
        self::assertNull($stray[0][1]);
    }

    private function contentWidth(): float
    {
        $page = Page::a4();
        return $page->width() - $page->margins()->left->points - $page->margins()->right->points;
    }

    public function test_fr_widths_are_plain_percentages_of_what_the_other_tracks_leave(): void
    {
        $cols = $this->xpath($this->html($this->body(Table::make()->columns('25% 1fr')->row('a', 'b'))), '//col');
        self::assertSame('width:25%', $this->style($cols[0]));
        self::assertSame('width:75%', $this->style($cols[1]));
        $cols = $this->xpath($this->html($this->body(Table::make()->columns('1fr 1fr')->row('a', 'b'))), '//col');
        self::assertSame('width:50%', $this->style($cols[0]));
        self::assertStringNotContainsString('table-layout', $this->style($this->xpath($this->html($this->body(Table::make()->columns('1fr')->row('a'))), '//table')[0]));
    }

    public function test_length_fr_mix_at_page_level_gives_exact_percentages(): void
    {
        $cols = $this->xpath($this->html($this->body(Table::make()->columns('30mm 2fr 1fr')->row('a', 'b', 'c'))), '//col');
        $left = 100 - (30 * 72 / 25.4) / $this->contentWidth() * 100;
        self::assertSame('width:85.039pt', $this->style($cols[0]));
        self::assertSame('width:' . Css::num($left * 2 / 3) . '%', $this->style($cols[1]));
        self::assertSame('width:' . Css::num($left / 3) . '%', $this->style($cols[2]));
    }

    public function test_estimated_width_subtracts_ancestor_and_own_edges(): void
    {
        $table = Table::make()->columns('30pt 1fr')->padding(pt(5))->row('a', 'b');
        $doc = $this->body(Column::make($table)->padding(pt(10))->margin(pt(2)));
        $est = $this->contentWidth() - 2 * 10 - 2 * 2 - 2 * 5;
        $cols = $this->xpath($this->html($doc), '//col');
        self::assertSame('width:' . Css::num(100 - 30 / $est * 100) . '%', $this->style($cols[1]));
    }

    public function test_no_col_mixes_percent_and_pt_anywhere(): void
    {
        $files = [...(glob(__DIR__ . '/../Visual/fixtures/*.php') ?: []), ...(glob(__DIR__ . '/../../../../examples/core/documents/*.php') ?: [])];
        self::assertNotSame([], $files);
        $cols = 0;
        foreach ($files as $file) {
            $doc = require $file;
            self::assertInstanceOf(Document::class, $doc);
            foreach ($this->xpath($this->html($doc), '//col') as $col) {
                $cols++;
                $style = $this->style($col);
                self::assertFalse(str_contains($style, '%') && str_contains($style, 'pt'), $file . ': ' . $style);
                self::assertStringNotContainsString('calc', $style);
            }
        }
        self::assertGreaterThan(0, $cols);
    }

    public function test_cell_children_sit_in_a_column_wrapper_that_counts_as_flex(): void
    {
        $cell = TableCell::make(Text::make('a'), Spacer::size(pt(12)), Text::make('b'))->attributes(['gap' => pt(4), 'justify' => Justify::Center]);
        $html = $this->html($this->body(Table::make()->columns('1fr')->row($cell)));
        $td = $this->xpath($html, '//td')[0];
        self::assertStringContainsString('vertical-align:middle', $this->style($td));
        $wrap = $this->xpath($html, '//td/div')[0];
        self::assertSame('display:flex;flex-direction:column;gap:4pt;justify-content:center', $this->style($wrap));
        self::assertCount(1, $this->xpath($html, '//td/div/div[@style="flex:0 0 12pt"]'));
        $end = $this->html($this->body(Table::make()->columns('1fr')->row(TableCell::make('x')->attributes(['justify' => Justify::End]))));
        self::assertStringContainsString('vertical-align:bottom', $this->style($this->xpath($end, '//td')[0]));
    }

    public function test_row_padding_is_dropped_and_reported(): void
    {
        $messages = [];
        $html = $this->html($this->body(Table::make()->columns('1fr')->bodyRow(TableRow::make('a')->padding(pt(6)))), new HtmlOptions(onWarning: function (string $m) use (&$messages): void {
            $messages[] = $m;
        }));
        self::assertStringNotContainsString('padding', $this->style($this->xpath($html, '//tr')[0]));
        self::assertCount(1, array_filter($messages, static fn (string $m): bool => str_contains($m, 'row padding')));
        $none = [];
        $this->html($this->body(Table::make()->columns('1fr')->row('a')), new HtmlOptions(onWarning: function (string $m) use (&$none): void {
            $none[] = $m;
        }));
        self::assertCount(0, array_filter($none, static fn (string $m): bool => str_contains($m, 'row padding')));
    }

    public function test_block_link_keeps_flex_container_props_as_a_column(): void
    {
        $html = $this->html($this->body(Link::to('https://example.com', Text::make('a'), Text::make('b'))->attributes(['gap' => pt(3), 'justify' => Justify::Center, 'direction' => Direction::Row])));
        $a = $this->xpath($html, '//a')[0];
        self::assertSame('display:flex;flex-direction:column;gap:3pt;justify-content:center', $this->style($a));
    }

    public function test_nested_links_render_one_anchor_and_one_report(): void
    {
        $messages = [];
        $doc = $this->body(Link::to('https://outer.example', Text::spans(\Papercut\Span::text('plain '), new \Papercut\Span('inner', href: 'https://inner.example'), new \Papercut\Span(' more', href: 'https://inner2.example'))));
        $html = $this->html($doc, new HtmlOptions(onWarning: function (string $m) use (&$messages): void {
            $messages[] = $m;
        }));
        $warnings = [];
        set_error_handler(static function (int $no, string $str) use (&$warnings): bool {
            $warnings[] = $str;
            return true;
        });
        try {
            $parsed = \Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><head><title>t</title></head><body>' . $html . '</body></html>');
        } finally {
            restore_error_handler();
        }
        self::assertSame([], $warnings);
        self::assertCount(1, $parsed->getElementsByTagName('a'));
        self::assertCount(1, $this->xpath($html, '//a'));
        self::assertSame('https://outer.example', $this->xpath($html, '//a')[0]->getAttribute('href'));
        self::assertCount(0, $this->xpath($html, '//a//a'));
        self::assertSame('plain inner more', $this->xpath($html, '//a')[0]->textContent);
        $nested = array_values(array_filter($messages, static fn (string $m): bool => str_contains($m, 'nested link')));
        self::assertCount(1, $nested);
        self::assertStringContainsString('page > body > link[0] > text[0]', $nested[0]);
    }

    public function test_block_link_inside_block_link_is_a_span(): void
    {
        $messages = [];
        $doc = $this->body(Link::to('https://a.example', Link::to('https://b.example', Text::make('x'))));
        $html = $this->html($doc, new HtmlOptions(onWarning: function (string $m) use (&$messages): void {
            $messages[] = $m;
        }));
        self::assertCount(1, $this->xpath($html, '//a'));
        self::assertCount(1, $this->xpath($html, '//a/span[@class="pc-link"]'));
        self::assertCount(1, array_filter($messages, static fn (string $m): bool => str_contains($m, 'nested link')));
        // A sibling link after the nest is a real anchor again.
        $after = $this->html($this->body(Link::to('https://a.example', Link::to('https://b.example', 'x')), Link::to('https://c.example', 'y')));
        self::assertCount(2, $this->xpath($after, '//a'));
    }

    public function test_track_count_mismatch_warns_like_pdf(): void
    {
        $doc = $this->body(Table::make()->columns('10pt 20pt 30pt')->row('a', 'b'));
        $htmlW = [];
        $this->html($doc, new HtmlOptions(onWarning: function (string $m) use (&$htmlW): void {
            $htmlW[] = $m;
        }));
        $pdfW = [];
        $doc->toPdf(new PdfOptions(onWarning: function (string $m) use (&$pdfW): void {
            $pdfW[] = $m;
        }));
        $f = static fn (array $l): array => array_values(array_filter($l, static fn (string $m): bool => str_contains($m, 'columns but')));
        self::assertCount(1, $f($pdfW));
        self::assertSame($f($pdfW), $f($htmlW));
    }

    public function test_corrupt_images_with_valid_magic_bytes_throw_like_pdf(): void
    {
        // The HTML path asks the PDF backend's parser why, so the text is identical for every corruption it can detect.
        // A header-only PNG (signature + IHDR) is covered in test_truncated_png_and_jpeg_throw_like_pdf.
        foreach (["\x89PNG\r\n\x1a\n garbage", "\xFF\xD8\xFF garbage"] as $bytes) {
            $doc = $this->body(Image::bytes($bytes));
            $messages = [];
            foreach (['toHtml', 'toPdf'] as $m) {
                try {
                    $doc->$m();
                    self::fail($m . ' did not throw');
                } catch (ImageUnreadable $e) {
                    $messages[] = $e->getMessage();
                }
            }
            self::assertSame($messages[1], $messages[0]);
        }
        $path = tempnam(sys_get_temp_dir(), 'pc') ?: throw new \RuntimeException('tmp');
        file_put_contents($path, "\x89PNG\r\n\x1a\n garbage");
        try {
            $doc = $this->body(Image::file($path));
            $messages = [];
            foreach (['toHtml', 'toPdf'] as $m) {
                try {
                    $doc->$m();
                } catch (ImageUnreadable $e) {
                    $messages[] = $e->getMessage();
                }
            }
            self::assertCount(2, $messages);
            self::assertSame($messages[1] ?? null, $messages[0] ?? null);
        } finally {
            unlink($path);
        }
    }

    public function test_truncated_png_and_jpeg_throw_like_pdf(): void
    {
        // Header-only PNG: getimagesizefromstring() (the old HTML gate) accepts it, PdfImage refuses it, so HTML must too.
        // A 60% cut of a PNG or JPEG is accepted by PdfImage (pinned below), so there HTML and PDF both succeed.
        $png = TestImages::png(40, 20);
        $cases = [
            'png header only' => [substr($png, 0, 33), true],
            'png 60%' => [substr($png, 0, (int) (strlen($png) * 0.6)), false],
            'jpeg 60%' => [substr(TestImages::jpeg(40, 20), 0, (int) (strlen(TestImages::jpeg(40, 20)) * 0.6)), false],
        ];
        foreach ($cases as $name => [$bytes, $refused]) {
            $path = tempnam(sys_get_temp_dir(), 'pc') ?: throw new \RuntimeException('tmp');
            file_put_contents($path, $bytes);
            try {
                foreach (['bytes' => Image::bytes($bytes), 'file' => Image::file($path)] as $form => $image) {
                    $doc = $this->body($image);
                    $label = $name . ' ' . $form;
                    if (!$refused) {
                        $doc->toPdf();
                        self::assertStringContainsString('<img', $doc->toHtml()->bytes(), $label);
                        continue;
                    }
                    try {
                        $doc->toPdf();
                        self::fail($label . ': toPdf() did not throw');
                    } catch (ImageUnreadable $pdf) {
                    }
                    try {
                        $doc->toHtml();
                        self::fail($label . ': toHtml() did not throw');
                    } catch (ImageUnreadable $html) {
                        self::assertSame($pdf->getMessage(), $html->getMessage(), $label);
                    }
                }
            } finally {
                unlink($path);
            }
        }
    }

    /** @return \Papercut\Backend\Backend&object{loads: int} */
    private function countingBackend(string $method): \Papercut\Backend\Backend
    {
        return new class ($method) implements Backend {
            public int $loads = 0;
            private readonly Backend $inner;

            public function __construct(private readonly string $counted)
            {
                $this->inner = new PhpPdfBackend(false);
            }

            public function loadFont(string $path): FontHandle
            {
                return $this->inner->loadFont($path);
            }

            public function loadImageFile(string $path): ImageHandle
            {
                $this->counted === 'file' && $this->loads++;
                return $this->inner->loadImageFile($path);
            }

            public function loadImageBytes(string $bytes): ImageHandle
            {
                $this->counted === 'bytes' && $this->loads++;
                return $this->inner->loadImageBytes($bytes);
            }

            public function render(DocumentMeta $meta, array $pages): string
            {
                return $this->inner->render($meta, $pages);
            }
        };
    }

    public function test_image_used_many_times_is_loaded_once(): void
    {
        $png = TestImages::png(40, 20);
        $path = tempnam(sys_get_temp_dir(), 'pc') ?: throw new \RuntimeException('tmp');
        file_put_contents($path, $png);
        try {
            foreach (['bytes' => static fn (): Image => Image::bytes($png), 'file' => static fn (): Image => Image::file($path)] as $kind => $make) {
                $backend = $this->countingBackend($kind);
                $doc = $this->body(...array_map($make, range(1, 50)));
                $options = HtmlOptions::from(new HtmlOptions());
                $writer = new HtmlWriter($doc, new Fidelity('html', $options), $options, $backend);
                (new TreeWalker(new StyleResolver($options->theme())))->walk($doc, $writer);

                self::assertSame(50, substr_count($writer->html(), '<img'), $kind);
                self::assertSame(1, $backend->loads, $kind);
            }
        } finally {
            unlink($path);
        }
    }

    public function test_table_and_row_text_styles_cascade(): void
    {
        $red = \Papercut\Value\Color::hex('#ff0000');
        $blue = \Papercut\Value\Color::hex('#0000ff');
        $html = $this->html($this->body(Table::make()->columns('1fr 1fr')->color($red)->bodyRow(TableRow::make('a', TableCell::make('b')->color($blue))->italic())));
        self::assertStringContainsString('color:#ff0000', $this->style($this->xpath($html, '//table')[0]));
        self::assertStringContainsString('font-style:italic', $this->style($this->xpath($html, '//tr')[0]));
        self::assertStringNotContainsString('color', $this->style($this->xpath($html, '//tr')[0]));
        self::assertStringNotContainsString('italic', $this->style($this->xpath($html, '//td')[0]));
        self::assertStringContainsString('color:#0000ff', $this->style($this->xpath($html, '//td')[1]));
    }

    public function test_unsupported_image_messages_match_pdf(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pc') ?: throw new \RuntimeException('tmp');
        file_put_contents($path, 'not an image');
        try {
            foreach ([Image::bytes('GIF89a'), Image::file($path), Image::file('/no/such.png')] as $image) {
                $doc = $this->body($image);
                $messages = [];
                foreach (['toHtml', 'toPdf'] as $m) {
                    try {
                        $doc->$m();
                    } catch (ImageUnreadable $e) {
                        $messages[] = $e->getMessage();
                    }
                }
                self::assertCount(2, $messages);
                self::assertSame($messages[1] ?? null, $messages[0] ?? null);
            }
        } finally {
            unlink($path);
        }
    }

    public function test_refused_image_url_embeds_and_warns(): void
    {
        $png = TestImages::png();
        foreach (['', '/\\evil.example/x.png', '\\\\host\\x.png', 'javascript:x'] as $bad) {
            $warnings = [];
            $html = $this->html($this->body(Image::bytes($png)), new HtmlOptions(imageUrl: static fn (Image $i): string => $bad, onWarning: function (string $m) use (&$warnings): void {
                $warnings[] = $m;
            }));
            self::assertStringStartsWith('data:image/png', (string) $this->xpath($html, '//img')[0]->getAttribute('src'), $bad);
            self::assertSame(['[page > body > image[0]] imageUrl returned an unsafe URL; the image was embedded instead'], array_values(array_filter($warnings, static fn (string $m): bool => str_contains($m, 'imageUrl'))), $bad);
        }
        $warnings = [];
        $this->html($this->body(Image::bytes($png)), new HtmlOptions(imageUrl: static fn (Image $i): ?string => null, onWarning: function (string $m) use (&$warnings): void {
            $warnings[] = $m;
        }));
        self::assertSame([], array_values(array_filter($warnings, static fn (string $m): bool => str_contains($m, 'imageUrl'))));
    }

    public function test_row_outside_table_throws_like_pdf(): void
    {
        $doc = $this->body(TableRow::make('a'));
        $messages = [];
        foreach (['toHtml', 'toPdf'] as $m) {
            try {
                $doc->$m();
                self::fail('expected LogicException');
            } catch (\LogicException $e) {
                $messages[] = $e->getMessage();
            }
        }
        self::assertSame($messages[1], $messages[0]);
        self::assertStringStartsWith('No layouter registered for Papercut\\TableRow at', $messages[0]);
    }

    public function test_cell_decoration_override_is_approximated(): void
    {
        $messages = [];
        $this->html($this->body(Table::make()->columns('1fr')->underline()->bodyRow(TableRow::make(TableCell::make('a')->underline(false)))), new HtmlOptions(onWarning: function (string $m) use (&$messages): void {
            $messages[] = $m;
        }));
        self::assertCount(1, array_filter($messages, static fn (string $m): bool => str_contains($m, 'text decoration override')));
    }

    public function test_cell_space_justify_is_approximated(): void
    {
        foreach ([Justify::SpaceBetween, Justify::SpaceAround, Justify::SpaceEvenly, Justify::Center, Justify::End] as $j) {
            $messages = [];
            $this->html($this->body(Table::make()->columns('1fr')->row(TableCell::make('a')->attributes(['justify' => $j]))), new HtmlOptions(onWarning: function (string $m) use (&$messages): void {
                $messages[] = $m;
            }));
            $found = array_values(array_filter($messages, static fn (string $m): bool => str_contains($m, 'cell justify')));
            if (in_array($j, [Justify::Center, Justify::End], true)) {
                self::assertSame([], $found, $j->value);
            } else {
                self::assertCount(1, $found, $j->value);
                self::assertStringStartsWith('html: approximated cell justify at ', $found[0]);
                self::assertStringEndsWith('table[0] > tr[0] > td[0]', $found[0]);
            }
        }
    }

    public function test_table_and_row_decoration_override_is_approximated(): void
    {
        $run = function (Table $t): array {
            $messages = [];
            $this->html($this->body($t), new HtmlOptions(onWarning: function (string $m) use (&$messages): void {
                $messages[] = $m;
            }));

            return array_values(array_filter($messages, static fn (string $m): bool => str_contains($m, 'text decoration override')));
        };
        $table = $run(Table::make()->columns('1fr')->row('a')->underline(false));
        self::assertCount(0, $table);
        $outer = Column::make(Table::make()->columns('1fr')->row('a')->underline(false))->underline();
        $messages = [];
        $this->html($this->body($outer), new HtmlOptions(onWarning: function (string $m) use (&$messages): void {
            $messages[] = $m;
        }));
        self::assertContains('html: approximated text decoration override at page > body > column[0] > table[0]', $messages);
        $row = $run(Table::make()->columns('1fr')->underline()->bodyRow(TableRow::make('a')->underline(false)));
        self::assertCount(1, $row);
        self::assertStringEndsWith('table[0] > tr[0]', $row[0]);
    }
}
