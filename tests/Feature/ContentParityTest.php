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
        if (basename($file, '.php') === 'certificate') {
            self::markTestSkipped('The certificate is text over a PDF template, and the template, the point of the example, is dropped from Word and HTML.');
        }
        $source = require $file;
        self::assertInstanceOf(Document::class, $source);
        $pages = array_map(static fn (Page $p): Page => Page::size(pt($p->width()), pt($p->height()))->margin($p->margins())->body(...self::withoutEquations($p->bodyNodes())), $source->pages());
        $document = Document::make(...$pages);

        $headerWords = $this->headerWords($document);
        $toc = $this->hasToc($document);
        self::assertSame(
            $this->words($this->pdfText($document), $headerWords, $toc),
            $this->words($this->htmlText($document), $headerWords, $toc),
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

    /** Whether the document has a table of contents anywhere in its page bodies. */
    private function hasToc(Document $document): bool
    {
        $finder = new class implements TreeVisitor {
            public bool $found = false;

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
                $this->found = $this->found || $node instanceof \Papercut\Toc;

                return true;
            }

            public function leave(Node $node, ComputedStyle $style, Visit $at): void
            {
            }
        };
        (new TreeWalker(new StyleResolver(Theme::default())))->walk($document, $finder);

        return $finder->found;
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
     * textContent with a space around every block-level element, as a browser would lay it out (only span, code and a are
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
                $inline = in_array($child->localName, ['span', 'a', 'code'], true);
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
     * @param bool $toc whether the document has a table of contents, whose page numbers and leaders HTML does not repeat
     * @return array<string,int> word => occurrences, sorted by word
     */
    private function words(string $text, array $skip, bool $toc = false): array
    {
        // pdftotext dehyphenates a hyphenated word that wraps at the hyphen ("break-after" becomes "breakafter"), which
        // the HTML text cannot show, so hyphens are ignored on both sides.
        $text = str_replace('-', '', $text);
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $counts = [];
        foreach ($words as $w) {
            // A table of contents prints page numbers and dot leaders in the PDF; HTML links to the headings instead, so in a
            // document with one, leader runs and bare numbers are left out of both sides.
            if ($toc && preg_match('/^(\.+|\d+)$/', $w) === 1) {
                continue;
            }
            if (!isset($skip[$w])) {
                $counts[$w] = ($counts[$w] ?? 0) + 1;
            }
        }
        ksort($counts, SORT_STRING);

        return $counts;
    }

    /**
     * The nodes without any equation: a block Equation is dropped and an inline one's span is cut out of its text (the text around it
     * stays). The PDF sets a formula's letters as mathematical italics and the other formats hold them as math, so formula words
     * are not comparable. This helper exists in the DOCX package's tests too: the two packages share no test code.
     *
     * @param list<\Papercut\Node|\Papercut\Component> $nodes
     * @return list<\Papercut\Node|\Papercut\Component>
     */
    private static function withoutEquations(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $n) {
            if ($n instanceof \Papercut\Equation) {
                continue;
            }
            $out[] = self::withoutInlineMath($n);
        }

        return $out;
    }

    private static function withoutInlineMath(\Papercut\Node|\Papercut\Component $n): \Papercut\Node|\Papercut\Component
    {
        if ($n instanceof \Papercut\Text) {
            $spans = array_values(array_filter($n->spanList(), static fn (\Papercut\Span $s): bool => $s->math === null));

            return count($spans) === count($n->spanList()) ? $n : self::with($n, 'spans', $spans);
        }
        if ($n instanceof \Papercut\Table) {
            $n = self::with($n, 'headerRows', array_map(self::row(...), $n->headerRows()));

            return self::with($n, 'bodyRows', array_map(self::row(...), $n->bodyRows()));
        }
        if ($n instanceof \Papercut\View) {
            return $n->withChildren(...self::withoutEquations($n->children()));
        }

        return $n;
    }

    private static function row(\Papercut\TableRow $row): \Papercut\TableRow
    {
        return self::with($row, 'cells', array_map(
            static fn (\Papercut\TableCell $c): \Papercut\TableCell => self::with($c, 'children', self::withoutEquations($c->children())),
            $row->cells(),
        ));
    }

    /**
     * A copy of $o with its private property $prop set (these nodes have no setter for their own children).
     *
     * @template T of object
     * @param T $o
     * @return T
     */
    private static function with(object $o, string $prop, mixed $value): object
    {
        $clone = clone $o;
        \Closure::bind(function () use ($prop, $value): void {
            $this->$prop = $value;
        }, $clone, $o::class)();

        return $clone;
    }
}
