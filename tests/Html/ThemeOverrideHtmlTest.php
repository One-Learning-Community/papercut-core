<?php

declare(strict_types=1);

namespace Papercut\Tests\Html;

use Papercut\Value\Color;
use Papercut\{Document, Page, Text};
use Papercut\Style\ThemeOverride;
use Papercut\Export\HtmlOptions;
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

final class ThemeOverrideHtmlTest extends TestCase
{
    private function dom(Document $doc): \Dom\HTMLDocument
    {
        return \Dom\HTMLDocument::createFromString($doc->toHtml(new HtmlOptions(fullPage: true))->bytes());
    }

    public function test_document_override_sets_the_root_text_style(): void
    {
        $doc = Document::make(Page::a4()->body(Text::make('Hi')))->theme(new ThemeOverride(fontSize: pt(14)));
        $root = $this->dom($doc)->querySelector('.papercut');
        $this->assertStringContainsString('font-size:14pt', (string) $root?->getAttribute('style'));
    }

    public function test_page_override_is_written_on_its_section_only(): void
    {
        $doc = Document::make(
            Page::a4()->body(Text::make('One')),
            Page::a4()->theme(new ThemeOverride(color: Color::parse("red-500")))->body(Text::make('Two')),
            Page::a4()->body(Text::make('Three')),
        );
        $sections = $this->dom($doc)->querySelectorAll('section.pc-page');
        $this->assertCount(3, $sections);
        $this->assertStringNotContainsString('color:', (string) $sections[0]->getAttribute('style'));
        $this->assertStringContainsString('color:#ef4444', (string) $sections[1]->getAttribute('style'));
        $this->assertStringNotContainsString('color:', (string) $sections[2]->getAttribute('style'));
    }

    public function test_no_overrides_is_byte_identical(): void
    {
        $page = fn() => Page::a4()->body(Text::make('Hi'));
        $plain = Document::make($page(), $page())->toHtml()->bytes();
        $empty = Document::make($page(), $page()->theme(new ThemeOverride()))->theme(new ThemeOverride())->toHtml()->bytes();
        $this->assertSame($plain, $empty);
    }

    public function test_a_page_after_an_override_uses_the_document_theme_again(): void
    {
        $toc = fn() => Page::a4()->body(\Papercut\Toc::make()->title('Contents'), Text::h1('Head'));
        $over = new ThemeOverride(styles: ['toc.title' => \Papercut\Style\Style::make(color: Color::parse('red-500'))]);
        $doc = Document::make($toc()->theme($over), $toc());
        $html = $doc->toHtml(new HtmlOptions(fullPage: true))->bytes();
        $this->assertSame(1, substr_count($html, 'color:#ef4444'));
    }
}
