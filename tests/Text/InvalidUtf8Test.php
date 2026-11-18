<?php

declare(strict_types=1);

namespace Quoin\Tests\Text;

use Quoin\{Document, Equation, Link, Page, PageNumber, Span, Table, Text, Toc};
use Quoin\Exception\InvalidValue;
use Quoin\Tests\Support\PdfInspector;
use Quoin\Decorator\{Badge, CodeBlock};
use Quoin\Export\{HtmlOptions, PdfOptions};
use Phactorial\Math;
use PHPUnit\Framework\TestCase;

final class InvalidUtf8Test extends TestCase
{
    private const NOTE = 'invalid UTF-8 replaced with U+FFFD';

    protected function setUp(): void
    {
        set_error_handler(static function (int $no, string $msg, string $file, int $line): never {
            throw new \ErrorException($msg, 0, $no, $file, $line);
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
    }

    private static function latin1(): string
    {
        return mb_convert_encoding('café au lait', 'ISO-8859-1', 'UTF-8');
    }

    /** @return list<string> */
    private function pdfMessages(Document $doc): array
    {
        $messages = [];
        $doc->toPdf(new PdfOptions(onWarning: function (string $m) use (&$messages): void {
            $messages[] = $m;
        }, cacheDir: false));
        return $messages;
    }

    private function assertOneNote(Document $doc): void
    {
        $messages = $this->pdfMessages($doc);
        self::assertCount(1, $messages, implode("\n", $messages));
        self::assertStringContainsString(self::NOTE, $messages[0]);
    }

    public function test_text_is_scrubbed_with_one_note(): void
    {
        $text = Text::make(self::latin1());
        self::assertSame("caf\u{FFFD} au lait", $text->spanList()[0]->text);
        self::assertCount(1, $text->notes());
        self::assertSame(self::NOTE, $text->notes()[0]->feature);
        $doc = Document::make(Page::a4()->body($text));
        $this->assertOneNote($doc);
        self::assertStringContainsString("caf\u{FFFD} au lait", $doc->toHtml(new HtmlOptions())->bytes());
    }

    public function test_valid_text_is_untouched_and_silent(): void
    {
        $text = Text::make("caf\u{E9} \u{F1}");
        self::assertSame("caf\u{E9} \u{F1}", $text->spanList()[0]->text);
        self::assertSame([], $text->notes());
        self::assertSame([], $this->pdfMessages(Document::make(Page::a4()->body($text))));
    }

    public function test_middle_byte_keeps_the_letters_around_it(): void
    {
        $text = Text::p("abc\xffdef ghi");
        self::assertSame("abc\u{FFFD}def ghi", $text->spanList()[0]->text);
    }

    public function test_every_text_entry_point_scrubs(): void
    {
        $bad = "a\xffb";
        $texts = [
            Text::h1($bad), Text::code($bad), Text::spans(Span::text($bad)), Text::spans(Span::code($bad)),
            Text::make('ok')->append($bad), Text::markup($bad),
        ];
        foreach ($texts as $i => $t) {
            foreach ($t->spanList() as $span) {
                self::assertTrue(mb_check_encoding($span->text, 'UTF-8'), "entry $i");
            }
            self::assertCount(1, $t->notes(), "entry $i");
        }
        $this->assertOneNote(Document::make(Page::a4()->body(Text::spans(Span::badge($bad)))));
    }

    public function test_links_cells_and_blocks_export_with_one_note(): void
    {
        $this->assertOneNote(Document::make(Page::a4()->body(Link::to('https://example.com', "a\xffb"))));
        $this->assertOneNote(Document::make(Page::a4()->body(Table::make()->row("a\xffb", 'c'))));
        $this->assertOneNote(Document::make(Page::a4()->body(Badge::make("a\xffb"))));
    }

    public function test_code_block_with_bad_bytes(): void
    {
        $doc = Document::make(Page::a4()->body(CodeBlock::make("x=\xff1")->language('php')));
        $messages = $this->pdfMessages($doc);
        self::assertCount(1, $messages, implode("\n", $messages));
        self::assertStringContainsString("=\u{FFFD}1", strip_tags(str_replace("</span>", "", $doc->toHtml(new HtmlOptions())->bytes())));
        $this->assertOneNote(Document::make(Page::a4()->body(CodeBlock::make("x=\xff1"))));
    }

    public function test_equations_and_math_spans_note_once(): void
    {
        $this->assertOneNote(Document::make(Page::a4()->body(Equation::of("x\xff"))));
        $this->assertOneNote(Document::make(Page::a4()->body(Text::spans(Span::text('a '), Span::math("x\xff")))));
    }

    public function test_replacement_character_is_drawn_in_the_pdf(): void
    {
        $doc = Document::make(Page::a4()->body(Text::make(self::latin1())));
        $text = PdfInspector::text($doc->toPdf(new PdfOptions(cacheDir: false))->bytes());
        self::assertStringContainsString("caf\u{FFFD} au lait", $text);
    }

    public function test_strict_pdf_and_html_export_scrubbed_text(): void
    {
        $doc = Document::make(Page::a4()->body(Text::make(self::latin1()), Text::spans(Span::text('x '), Span::math("y\xff"))));
        $doc->toPdf(new PdfOptions(strict: true, cacheDir: false));
        self::assertStringContainsString("caf\u{FFFD}", $doc->toHtml(new HtmlOptions(strict: true))->bytes());
    }

    public function test_page_number_format_rejects_invalid_utf8(): void
    {
        $this->expectException(InvalidValue::class);
        $this->expectExceptionMessage('UTF-8');
        PageNumber::make("p {page} a\xffb");
    }

    public function test_document_metadata_rejects_invalid_utf8(): void
    {
        foreach (['title', 'author', 'subject', 'keywords'] as $setter) {
            try {
                Document::make(Page::a4()->body(Text::make('x')))->$setter("a\xffb");
                self::fail("$setter accepted invalid UTF-8");
            } catch (InvalidValue $e) {
                self::assertStringContainsString($setter, $e->getMessage());
                self::assertStringContainsString('UTF-8', $e->getMessage());
            }
        }
        Document::make(Page::a4()->body(Text::make('x')))->title("caf\u{E9}")->author('a');
    }

    public function test_toc_title_is_scrubbed_with_a_note_on_the_toc(): void
    {
        $toc = Toc::make()->title("a\xffb");
        self::assertSame("a\u{FFFD}b", $toc->titleText());
        self::assertCount(1, $toc->notes());
        $doc = Document::make(Page::a4()->body($toc, Text::h1('Heading')));
        $this->assertOneNote($doc);
        $html = [];
        $doc->toHtml(new HtmlOptions(onWarning: function (string $m) use (&$html): void {
            $html[] = $m;
        }));
        self::assertCount(1, array_filter($html, static fn (string $m): bool => str_contains($m, self::NOTE)), implode("\n", $html));
    }
}
