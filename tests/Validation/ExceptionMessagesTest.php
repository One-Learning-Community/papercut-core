<?php

declare(strict_types=1);

namespace Papercut\Tests\Validation;

use Papercut\Exception\{FontNotFound, InvalidStructure, InvalidValue};
use Papercut\Export\{FontMode, HtmlOptions};
use Papercut\Style\{Style, ThemeOverride};
use Papercut\Text\{FontFace, FontSet};
use Papercut\{Column, Document, Image, ListItem, ListNode, Page, Place, QrCode, Table, TableCell, TableRow, Text, View};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

/** Developer mistakes raise Papercut exceptions that name the call, the value and what is allowed (D1, D10). */
final class ExceptionMessagesTest extends TestCase
{
    protected function setUp(): void
    {
        set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
            if ((error_reporting() & $no) === 0) {
                return false;
            }
            throw new \ErrorException($msg, 0, $no, $file, $line);
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
    }

    public function test_a_null_style_attribute_is_not_set(): void
    {
        self::assertEquals(Style::fromAttributes([]), Style::fromAttributes(['width' => null]));
    }

    public function test_an_array_style_attribute_is_invalid_value(): void
    {
        $this->expectException(InvalidValue::class);
        Style::fromAttributes(['width' => []]);
    }

    /** @return iterable<string, array{\Closure(): mixed, string, string}> */
    public static function enums(): iterable
    {
        yield 'qr shape' => [static fn () => QrCode::make('x')->shape('abc'), 'QrCode::shape()', 'square'];
        yield 'qr eyes' => [static fn () => QrCode::make('x')->eyes('abc'), 'QrCode::eyes()', 'dot'];
        yield 'qr level' => [static fn () => QrCode::make('x')->errorCorrection('abc'), 'QrCode::errorCorrection()', 'M'];
        yield 'qr gradient' => [static fn () => QrCode::make('x')->gradient('#000', '#fff', 'abc'), 'QrCode::gradient()', 'radial'];
        yield 'image fit' => [static fn () => Image::bytes('x')->fit('abc'), 'Image::fit()', 'cover'];
        yield 'list marker' => [static fn () => ListNode::ordered('a')->marker('abc'), 'ListNode::marker()', 'decimal'];
        yield 'cell align' => [static fn () => TableCell::make('a')->align('abc'), 'TableCell::align()', 'center'];
        yield 'place anchor' => [static fn () => Place::at(pt(1), pt(1))->anchor('abc'), 'Place::anchor()', 'top-left'];
        yield 'direction' => [static fn () => Column::make()->direction('abc'), 'View::direction()', 'row'];
        yield 'justify' => [static fn () => Column::make()->justify('abc'), 'View::justify()', 'center'];
        yield 'align' => [static fn () => Column::make()->align('abc'), 'View::align()', 'center'];
        yield 'border style' => [static fn () => Column::make()->border(pt(1), '#000', 'abc'), '::border()', 'dashed'];
    }

    /** @param \Closure(): mixed $make */
    #[DataProvider('enums')]
    public function test_enum_errors_name_the_setter_and_list_the_choices(\Closure $make, string $setter, string $choice): void
    {
        try {
            $make();
            self::fail('expected InvalidValue');
        } catch (InvalidValue $e) {
            self::assertStringContainsString('"abc"', $e->getMessage());
            self::assertStringContainsString($setter, $e->getMessage());
            self::assertStringContainsString('expected one of', $e->getMessage());
            self::assertStringContainsString($choice, $e->getMessage());
        }
    }

    private function export(Document $d): void
    {
        $d->toPdf();
    }

    public function test_a_place_outside_an_overlay_is_invalid_structure(): void
    {
        $this->expectException(InvalidStructure::class);
        $this->expectExceptionMessage('place only works');
        $this->export(Document::make(Page::a4()->body(Column::make(Place::at(pt(1), pt(1), 'x')))));
    }

    public function test_a_list_item_in_a_column_is_invalid_structure(): void
    {
        $this->expectException(InvalidStructure::class);
        $this->expectExceptionMessage('ListItem');
        $this->export(Document::make(Page::a4()->body(Column::make(ListItem::make('x')))));
    }

    public function test_a_table_row_in_a_column_is_invalid_structure(): void
    {
        $this->expectException(InvalidStructure::class);
        $this->expectExceptionMessage('TableRow');
        $this->export(Document::make(Page::a4()->body(Column::make(TableRow::make('x')))));
    }

    public function test_a_page_in_a_view_is_invalid_structure(): void
    {
        $this->expectException(InvalidStructure::class);
        $this->expectExceptionMessage('Page');
        $this->export(Document::make(Page::a4()->body(Column::make(Page::a4()))));
    }

    public function test_a_page_in_a_view_is_invalid_structure_in_html_too(): void
    {
        $this->expectException(InvalidStructure::class);
        $this->expectExceptionMessage('Page cannot go here');
        Document::make(Page::a4()->body(Column::make(Page::a4())))->toHtml();
    }

    public function test_an_html_font_face_with_an_empty_path_is_font_not_found(): void
    {
        $fonts = new FontSet(faces: [new FontFace('Ghost', '')]);
        $this->expectException(FontNotFound::class);
        Document::make(Page::a4()->body(Text::make('x')->font('Ghost')))
            ->toHtml(new HtmlOptions(fonts: $fonts, fontMode: FontMode::Embed));
    }

    public function test_an_html_font_face_with_a_missing_file_is_font_not_found(): void
    {
        $fonts = new FontSet(faces: [new FontFace('Ghost', '/nonexistent/ghost.ttf')]);
        $this->expectException(FontNotFound::class);
        Document::make(Page::a4()->body(Text::make('x')->font('Ghost')))
            ->toHtml(new HtmlOptions(fonts: $fonts, fontMode: FontMode::Embed));
    }

    /** @return iterable<string, array{\Closure(): mixed, string}> */
    public static function themeOverrides(): iterable
    {
        yield 'line height NAN' => [static fn () => new ThemeOverride(lineHeight: NAN), 'NAN'];
        yield 'line height INF' => [static fn () => new ThemeOverride(lineHeight: INF), 'INF'];
        yield 'style not a Style' => [static fn () => new ThemeOverride(styles: ['a' => 'str']) // @phpstan-ignore argument.type
            , 'styles'];
        yield 'style int key' => [static fn () => new ThemeOverride(styles: [0 => new Style()]) // @phpstan-ignore argument.type
            , 'styles'];
        yield 'style empty name' => [static fn () => new ThemeOverride(styles: ['' => new Style()]), 'styles'];
        yield 'weight 0' => [static fn () => new ThemeOverride(fontWeight: 0), 'font weight'];
        yield 'weight 5000' => [static fn () => new ThemeOverride(fontWeight: 5000), 'font weight'];
        yield 'min lines' => [static fn () => new ThemeOverride(minLines: -1), 'min lines'];
    }

    /** @param \Closure(): mixed $make */
    #[DataProvider('themeOverrides')]
    public function test_a_theme_override_is_validated_at_construction(\Closure $make, string $text): void
    {
        try {
            $make();
            self::fail('expected InvalidValue');
        } catch (InvalidValue $e) {
            self::assertStringContainsString($text, $e->getMessage());
        }
    }

    /** @return iterable<string, array{\Closure(): mixed}> */
    public static function badRows(): iterable
    {
        yield 'a TableRow' => [static fn (): TableRow => TableRow::make('a')];
        yield 'a string' => [static fn (): string => 'abc'];
        yield 'null' => [static fn (): mixed => null];
    }

    /** @param \Closure(): mixed $cb */
    #[DataProvider('badRows')]
    public function test_table_rows_wants_a_callback_returning_a_list_of_cells(\Closure $cb): void
    {
        try {
            Table::make()->rows([1], static fn () => $cb());
            self::fail('expected InvalidValue');
        } catch (InvalidValue $e) {
            self::assertStringContainsString('Table::rows()', $e->getMessage());
            self::assertStringContainsString('list of cells', $e->getMessage());
        }
    }

    /** @return iterable<string, array{float, string}> */
    public static function nonFiniteCells(): iterable
    {
        yield 'NAN' => [NAN, 'NAN'];
        yield 'INF' => [INF, 'INF'];
        yield '-INF' => [-INF, '-INF'];
    }

    #[DataProvider('nonFiniteCells')]
    public function test_a_non_finite_cell_value_is_invalid_value_without_a_warning(float $v, string $shown): void
    {
        try {
            Table::make()->rows([1], static fn () => ['a', $v]);
            self::fail('expected InvalidValue');
        } catch (InvalidValue $e) {
            self::assertStringContainsString($shown, $e->getMessage());
            self::assertStringContainsString('Table::rows()', $e->getMessage());
            self::assertStringContainsString('cell 1', $e->getMessage());
        }
    }

    /** @return iterable<string, array{\Closure(): iterable<mixed>}> */
    public static function iterableRows(): iterable
    {
        yield 'associative' => [static fn (): array => ['n' => 'a', 't' => 'b']];
        yield 'ArrayIterator' => [static fn (): \Iterator => new \ArrayIterator(['a', 'b'])];
        yield 'generator' => [static function (): \Generator {
            yield 'a';
            yield 'b';
        }];
    }

    /** @param \Closure(): iterable<mixed> $cb */
    #[DataProvider('iterableRows')]
    public function test_table_rows_accepts_any_iterable_of_cells(\Closure $cb): void
    {
        $t = Table::make()->rows([1], static fn () => $cb());
        self::assertCount(1, $t->bodyRows());
    }

    /** @return iterable<string, array{string}> */
    public static function missingFonts(): iterable
    {
        yield 'empty path' => [''];
        yield 'missing file' => ['/nonexistent/ghost.ttf'];
    }

    #[DataProvider('missingFonts')]
    public function test_a_missing_font_file_throws_in_none_mode(string $path): void
    {
        $this->expectException(FontNotFound::class);
        Document::make(Page::a4()->body(Text::make('x')->font('Ghost')))
            ->toHtml(new HtmlOptions(fonts: new FontSet(faces: [new FontFace('Ghost', $path)])));
    }

    #[DataProvider('missingFonts')]
    public function test_a_missing_font_file_throws_in_sources_mode(string $path): void
    {
        $this->expectException(FontNotFound::class);
        Document::make(Page::a4()->body(Text::make('x')->font('Ghost')))
            ->toHtml(new HtmlOptions(fonts: new FontSet(faces: [new FontFace('Ghost', $path)]), fontMode: FontMode::Sources, fontSources: [static fn () => null]));
    }

    public function test_an_unregistered_custom_node_reads_as_such(): void
    {
        $node = new class implements \Papercut\Node {
            public function debugName(): string
            {
                return 'x';
            }

            public function style(): Style
            {
                return new Style();
            }
        };
        $e = \Papercut\Layout\LayoutEngine::misplaced($node, 'page > body > x[0]');
        self::assertStringContainsString('no layouter is registered', $e->getMessage());
    }

    public function test_an_empty_document_fails_the_same_way_in_pdf_and_html(): void
    {
        foreach (['toPdf', 'toHtml'] as $export) {
            try {
                Document::make()->$export();
                self::fail("$export should throw");
            } catch (InvalidStructure $e) {
                self::assertStringContainsString('at least one page', $e->getMessage());
            }
        }
    }
}
