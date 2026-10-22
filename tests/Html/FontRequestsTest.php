<?php

declare(strict_types=1);

namespace Papercut\Tests\Html;

use Papercut\{Column, Document, Page, Span, Text};
use Papercut\Backend\DefaultBackend;
use Papercut\Export\{Fidelity, HtmlOptions};
use Papercut\Html\{FontRequests, FontVariant, HtmlWriter};
use Papercut\Style\{Style, StyleResolver};
use Papercut\Text\{FontFace, FontSet, FontStyle};
use Papercut\Tree\TreeWalker;
use PHPUnit\Framework\TestCase;

final class FontRequestsTest extends TestCase
{
    private function walk(Document $doc, ?HtmlOptions $o = null): HtmlWriter
    {
        $o ??= new HtmlOptions();
        $writer = new HtmlWriter($doc, new Fidelity('html', $o), $o, DefaultBackend::make(false));
        (new TreeWalker(new StyleResolver($o->theme())))->walk($doc, $writer);

        return $writer;
    }

    /** @return list<array{request: \Papercut\Html\FontRequest, path: string}> */
    private function collect(Document $doc, FontSet $fonts = new FontSet()): array
    {
        $writer = $this->walk($doc, new HtmlOptions(fonts: $fonts));

        return FontRequests::collect($writer->fontUsage(), 'Inter', $fonts);
    }

    /**
     * @param list<FontVariant> $faces
     * @return list<string>
     */
    private static function names(array $faces): array
    {
        return array_map(static fn (FontVariant $f): string => $f->weight . ' ' . $f->style->value, $faces);
    }

    public function test_registered_family_uses_registered_faces(): void
    {
        $requests = $this->collect(Document::make(Page::a4()->body(Text::make('a'))));

        self::assertCount(1, $requests);
        self::assertSame('Inter', $requests[0]['request']->family);
        self::assertTrue($requests[0]['request']->registered);
        self::assertSame(['400 normal', '700 normal', '400 italic', '700 italic'], self::names($requests[0]['request']->faces));
    }

    public function test_unregistered_family_uses_document_usage(): void
    {
        $doc = Document::make(Page::a4()->body(
            Text::make('a')->font('Roboto'),
            Text::spans(Span::text('plain '), Span::text('loud', new Style(fontWeight: 700, italic: true)))->font('Roboto'),
        ));
        $roboto = array_values(array_filter($this->collect($doc), static fn (array $r): bool => $r['request']->family === 'Roboto'));

        self::assertCount(1, $roboto);
        self::assertFalse($roboto[0]['request']->registered);
        self::assertSame(['400 normal', '700 italic'], self::names($roboto[0]['request']->faces));
    }

    public function test_family_names_dedupe_case_insensitively(): void
    {
        $doc = Document::make(Page::a4()->body(Text::make('a')->font('roboto'), Text::make('b')->font('Roboto')));
        $requests = $this->collect($doc);

        self::assertSame(['Inter', 'roboto'], array_map(static fn (array $r): string => $r['request']->family, $requests));
        self::assertSame(['inter', 'roboto'], array_keys($this->walk($doc)->fontUsage()));
    }

    public function test_fallback_without_usage_gets_the_union(): void
    {
        $doc = Document::make(Page::a4()->body(Text::make('a')->font('Roboto'), Text::spans(Span::text('b', new Style(fontWeight: 700)))->font('Roboto')));
        $requests = $this->collect($doc, (new FontSet())->withFallbacks('Noto Sans'));
        $noto = array_values(array_filter($requests, static fn (array $r): bool => $r['request']->family === 'Noto Sans'));

        self::assertCount(1, $noto);
        self::assertSame(['400 normal', '700 normal'], self::names($noto[0]['request']->faces));
        self::assertSame('document', $noto[0]['path']);
        self::assertFalse($noto[0]['request']->registered);
    }

    public function test_empty_usage_falls_back_to_regular(): void
    {
        $requests = FontRequests::collect([], 'Acme', (new FontSet())->withFallbacks('Noto Sans'));

        self::assertSame(['Acme', 'Noto Sans'], array_map(static fn (array $r): string => $r['request']->family, $requests));
        self::assertSame(['400 normal'], self::names($requests[0]['request']->faces));
        self::assertSame(['400 normal'], self::names($requests[1]['request']->faces));
    }

    public function test_registered_custom_family_in_fallbacks(): void
    {
        $face = new FontFace('Fall', __DIR__ . '/../../resources/fonts/Inter/Inter-Regular.ttf', 300);
        $fonts = (new FontSet([$face]))->withFallbacks('fall');
        $requests = $this->collect(Document::make(Page::a4()->body(Text::make('a'))), $fonts);
        $fall = array_values(array_filter($requests, static fn (array $r): bool => strtolower($r['request']->family) === 'fall'));

        self::assertCount(1, $fall);
        self::assertTrue($fall[0]['request']->registered);
        self::assertSame(['300 normal'], self::names($fall[0]['request']->faces));
    }

    public function test_first_node_path_is_reported(): void
    {
        $doc = Document::make(Page::a4()->body(Column::make(Text::make('a'), Text::make('b')->font('Roboto'))));
        $writer = $this->walk($doc);
        $usage = $writer->fontUsage();

        self::assertNull($usage['inter']['path']);
        self::assertSame('page > body > column[0] > text[1]', $usage['roboto']['path']);

        $requests = FontRequests::collect($usage, 'Inter', new FontSet());
        self::assertSame(['document', 'page > body > column[0] > text[1]'], array_map(static fn (array $r): string => $r['path'], $requests));
    }

    public function test_used_families_unchanged(): void
    {
        foreach (['invoice', 'report', 'transcript'] as $name) {
            $doc = require __DIR__ . "/../../../../examples/core/documents/$name.php";
            self::assertSame(['Inter'], $this->walk($doc)->usedFamilies(), $name);
        }

        $mixed = Document::make(Page::a4()->body(Text::make('a')->font('Roboto'), Text::make('b')->font('roboto'), Text::make('c')));
        // Case-sensitive on purpose: FontFaces deduplicates, and the output is pinned.
        self::assertSame(['Inter', 'Roboto', 'roboto'], $this->walk($mixed)->usedFamilies());
    }
}
