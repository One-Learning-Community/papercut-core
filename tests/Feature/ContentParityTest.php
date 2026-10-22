<?php

declare(strict_types=1);

namespace Papercut\Tests\Feature;

use Papercut\{Document, Node, Page, Text};
use Papercut\Style\{ComputedStyle, ListMarker, StyleResolver, Theme};
use Papercut\Tree\{Region, TreeVisitor, TreeWalker, Visit};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

/**
 * The words pdftotext finds in the PDF equal the words in the HTML text, counted with multiplicity (body only: no header, footer or background).
 * Table header rows repeat on every PDF page, which HTML has no equivalent of, so words that appear in any header row are left out of both sides.
 */
final class ContentParityTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function documents(): iterable
    {
        foreach (['fixtures' => __DIR__ . '/../Visual/fixtures', 'examples' => __DIR__ . '/../../../../examples/core/documents'] as $kind => $dir) {
            $files = glob("$dir/*.php") ?: [];
            sort($files);
            foreach ($files as $file) {
                yield $kind . '-' . basename($file, '.php') => [$file];
            }
        }
    }

    protected function setUp(): void
    {
        exec('command -v pdftotext 2>/dev/null', $out, $code);
        if ($code !== 0) {
            self::markTestSkipped('pdftotext (poppler) is not installed.');
        }
    }

    #[DataProvider('documents')]
    public function test_pdf_and_html_have_the_same_word_multisets(string $file): void
    {
        $source = require $file;
        self::assertInstanceOf(Document::class, $source);
        $pages = array_map(static fn (Page $p): Page => Page::size(pt($p->width()), pt($p->height()))->margin($p->margins())->body(...$p->bodyNodes()), $source->pages());
        $document = Document::make(...$pages);

        $headerWords = $this->headerWords($document);
        self::assertSame(
            $this->words($this->pdfText($document), $headerWords),
            $this->words($this->htmlText($document), $headerWords),
        );
    }

    #[DataProvider('documents')]
    public function test_html_export_does_not_change_pdf_bytes(string $file): void
    {
        $document = require $file;
        self::assertInstanceOf(Document::class, $document);
        $document = $document->createdAt(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $before = $document->toPdf()->bytes();
        $document->toHtml();
        $document->toHtml(new \Papercut\Export\HtmlOptions(fontMode: \Papercut\Export\FontMode::Embed));
        self::assertSame($before, $document->toPdf()->bytes());
    }

    private function pdfText(Document $document): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pc') ?: self::fail('tempnam');
        try {
            file_put_contents($path, $document->toPdf()->bytes());
            return (string) shell_exec('pdftotext -enc UTF-8 ' . escapeshellarg($path) . ' - 2>/dev/null');
        } finally {
            @unlink($path);
        }
    }

    private function htmlText(Document $document): string
    {
        $dom = \Dom\HTMLDocument::createFromString($document->toHtml(new \Papercut\Export\HtmlOptions(fullPage: true))->bytes());
        return $dom->body === null ? '' : $this->textOf($dom->body);
    }

    /**
     * textContent with a space around every block-level element, as a browser would lay it out (only span and a are
     * inline). List items get the marker the browser draws from CSS, since the PDF draws it as text.
     */
    private function textOf(\Dom\Node $node): string
    {
        $text = '';
        $number = $node instanceof \Dom\Element ? (int) ($node->getAttribute('start') ?? 1) : 1;
        foreach ($node->childNodes as $child) {
            if ($child instanceof \Dom\Text) {
                $text .= $child->data;
            } elseif ($child instanceof \Dom\Element) {
                $inline = in_array($child->localName, ['span', 'a'], true);
                $marker = '';
                if ($child->localName === 'li' && $node instanceof \Dom\Element) {
                    $number = $child->hasAttribute('value') ? (int) $child->getAttribute('value') : $number;
                    $marker = $this->listMarker($node)->label($number++) . ' ';
                }
                $text .= ($inline ? '' : ' ') . $marker . $this->textOf($child) . ($inline ? '' : ' ');
            }
        }
        return $text;
    }

    private function listMarker(\Dom\Element $list): ListMarker
    {
        preg_match('/list-style-type:\s*([a-z-]+)/', $list->getAttribute('style') ?? '', $m);
        return ListMarker::tryFrom($m[1] ?? '') ?? ListMarker::Disc;
    }

    /** @return array<string,true> every word in every table header row of the document */
    private function headerWords(Document $document): array
    {
        $collector = new class implements TreeVisitor {
            /** @var array<string,true> */
            public array $words = [];

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
                if ($node instanceof Text && $at->inHeader()) {
                    foreach ($node->spanList() as $span) {
                        foreach (preg_split('/\s+/u', trim(str_replace('-', '', $span->text)), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
                            $this->words[$w] = true;
                        }
                    }
                }

                return true;
            }

            public function leave(Node $node, ComputedStyle $style, Visit $at): void
            {
            }
        };
        (new TreeWalker(new StyleResolver(Theme::default())))->walk($document, $collector);

        return $collector->words;
    }

    /**
     * @param array<string,true> $skip words removed from the count
     * @return array<string,int> word => occurrences, sorted by word
     */
    private function words(string $text, array $skip): array
    {
        // pdftotext dehyphenates a hyphenated word that wraps at the hyphen ("break-after" becomes "breakafter"), which
        // the HTML text cannot show, so hyphens are ignored on both sides.
        $text = str_replace('-', '', $text);
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $counts = [];
        foreach ($words as $w) {
            if (!isset($skip[$w])) {
                $counts[$w] = ($counts[$w] ?? 0) + 1;
            }
        }
        ksort($counts, SORT_STRING);

        return $counts;
    }
}
