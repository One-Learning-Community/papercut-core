<?php

declare(strict_types=1);

namespace Papercut\Tests\Html;

use Papercut\{Document, Page, Text};
use Papercut\Export\{FidelityLevel, FidelityReport, FontMode, HtmlOptions};
use Papercut\Html\{FontAnswer, FontFaceSrc, FontRequest, GoogleFonts, LocalFonts};
use Papercut\Text\{FontFace, FontSet, FontStyle};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HtmlFontSourcesTest extends TestCase
{
    private const string INTER_LINK = 'https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,400;0,700;1,400;1,700&amp;display=swap';

    private function doc(string ...$families): Document
    {
        $texts = $families === [] ? [Text::make('Hello')] : array_map(static fn (string $f) => Text::make('Hello')->font($f), $families);

        return Document::make(Page::a4()->body(...$texts));
    }

    /** @param list<mixed> $sources */
    private function html(Document $doc, array $sources, bool $full = false, ?\Closure $onWarning = null, bool $strict = false, ?FontSet $fonts = null): string
    {
        return $doc->toHtml(new HtmlOptions(fonts: $fonts, onWarning: $onWarning, strict: $strict, fullPage: $full, fontMode: FontMode::Sources, fontSources: $sources))->bytes();
    }

    public function test_full_page_links_in_head(): void
    {
        $html = $this->html($this->doc(), [new GoogleFonts()], true);

        self::assertStringContainsString('<link rel="stylesheet" href="' . self::INTER_LINK . '">', $html);
        self::assertLessThan(strpos($html, '<style>'), strpos($html, '<link rel="stylesheet"'));
        self::assertStringNotContainsString('@import', $html);
        self::assertStringNotContainsString('@font-face', $html);
    }

    public function test_fragment_imports_first(): void
    {
        $html = $this->html($this->doc('Roboto'), [new GoogleFonts()]);

        self::assertStringStartsWith('<style>@import url("https://fonts.googleapis.com/css2?family=Inter', $html);
        self::assertStringNotContainsString('<link', $html);
        $lastImport = strrpos($html, '@import');
        self::assertLessThan(strpos($html, '.papercut{'), $lastImport);
        self::assertSame(2, substr_count($html, '@import'));
        $imports = substr($html, 0, (int) strpos($html, '.papercut{'));
        self::assertDoesNotMatchRegularExpression('/\}\s*@import/', $imports);
    }

    public function test_fragment_imports_come_before_font_faces(): void
    {
        $fonts = new FontSet([new FontFace('Acme', __DIR__ . '/../../resources/fonts/Inter/Inter-Regular.ttf')]);
        $html = $this->html($this->doc('Acme', 'Roboto'), [LocalFonts::embed(['Acme']), new GoogleFonts()], false, null, false, $fonts);

        self::assertLessThan(strpos($html, '@font-face'), strrpos($html, '@import'));
        self::assertLessThan(strpos($html, '.papercut{'), strpos($html, '@font-face'));
    }

    public function test_first_accepting_source_wins_and_later_sources_are_not_called(): void
    {
        $calls = [];
        $first = static function (FontRequest $r) use (&$calls): ?FontAnswer {
            $calls[] = 'first:' . $r->family;

            return $r->family === 'Acme' ? FontAnswer::stylesheet('https://a.test/acme.css') : null;
        };
        $second = static function (FontRequest $r) use (&$calls): ?FontAnswer {
            $calls[] = 'second:' . $r->family;

            return null;
        };
        $this->html($this->doc('Acme'), [$first, $second]);

        self::assertContains('first:Acme', $calls);
        self::assertNotContains('second:Acme', $calls);
        self::assertContains('second:Inter', $calls);
    }

    public function test_mixed_stack(): void
    {
        $fonts = new FontSet([new FontFace('Acme', __DIR__ . '/../../resources/fonts/Inter/Inter-Regular.ttf')]);
        $html = $this->html($this->doc('Acme'), [LocalFonts::embed(['Acme']), new GoogleFonts()], true, null, false, $fonts);

        self::assertStringContainsString('@font-face{font-family:"Acme";src:url(data:font/ttf;base64,', $html);
        self::assertStringContainsString('<link rel="stylesheet" href="' . self::INTER_LINK . '">', $html);
        self::assertSame(1, substr_count($html, '<link rel="stylesheet"'));
        self::assertStringNotContainsString('family=Acme', $html);
    }

    /** @return iterable<string, array{FontAnswer, string}> */
    public static function unsafeAnswers(): iterable
    {
        yield 'javascript stylesheet' => [FontAnswer::stylesheet('javascript:alert(1)'), 'javascript:alert(1)'];
        yield 'protocol-relative stylesheet' => [FontAnswer::stylesheet('//evil.test/x.css'), 'evil.test'];
        yield 'newline stylesheet' => [FontAnswer::stylesheet("https://x.test/a.css\n"), 'x.test'];
        yield 'empty faces' => [FontAnswer::faces(), 'x.test'];
        yield 'unsafe face url' => [FontAnswer::faces(new FontFaceSrc(400, FontStyle::Normal, 'javascript:x', 'truetype')), 'javascript:x'];
    }

    #[DataProvider('unsafeAnswers')]
    public function test_unsafe_answers_fall_through_and_warn(FontAnswer $bad, string $needle): void
    {
        $warnings = [];
        $sources = [static fn (FontRequest $r): FontAnswer => $bad, new GoogleFonts()];
        $html = $this->html($this->doc(), $sources, true, static function (string $m) use (&$warnings): void {
            $warnings[] = $m;
        });

        self::assertContains('font source 0 returned an unsafe URL for "Inter"; trying the next source', $warnings);
        self::assertStringContainsString(self::INTER_LINK, $html);
        self::assertStringNotContainsString($needle, $html);
    }

    public function test_no_source_reports_font_not_linked(): void
    {
        $reports = [];
        $html = $this->html($this->doc(), [static fn (FontRequest $r): ?FontAnswer => null], true, static function (string $m, ?FidelityReport $r = null) use (&$reports): void {
            if ($r !== null && $r->feature === 'font not linked') {
                $reports[] = $r;
            }
        }, true);

        self::assertStringNotContainsString('<link', $html);
        self::assertCount(1, $reports);
        self::assertSame(FidelityLevel::Approximated, $reports[0]->level);
        self::assertSame('html', $reports[0]->format);
        self::assertSame('font not linked', $reports[0]->feature);
        self::assertSame('document', $reports[0]->path);
    }

    public function test_identical_urls_emitted_once(): void
    {
        $same = static fn (FontRequest $r): FontAnswer => FontAnswer::stylesheet('https://x.test/all.css');
        $html = $this->html($this->doc('Roboto'), [$same], true);

        self::assertSame(1, substr_count($html, '<link rel="stylesheet"'));
    }

    public function test_url_escaping(): void
    {
        $src = static fn (FontRequest $r): FontAnswer => FontAnswer::stylesheet('https://x.test/a.css?f="<b>');

        $page = $this->html($this->doc(), [$src], true);
        self::assertStringContainsString('href="https://x.test/a.css?f=&quot;&lt;b&gt;"', $page);

        $fragment = $this->html($this->doc(), [$src]);
        self::assertStringStartsWith('<style>@import url("https://x.test/a.css?f=', $fragment);
        self::assertStringNotContainsString('f="<b>', $fragment);
        self::assertSame(1, preg_match('/@import url\("((?:[^"\\\\]|\\\\.)*)"\);/', $fragment, $m));
        self::assertStringContainsString('\\"', $m[1] ?? '');
    }

    public function test_sources_without_sources_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('FontMode::Sources needs at least one font source');
        $this->html($this->doc(), []);
    }

    public function test_objects_and_closures_both_accepted(): void
    {
        $html = $this->html($this->doc('Roboto'), [static fn (FontRequest $r): ?FontAnswer => $r->family === 'Roboto' ? FontAnswer::stylesheet('https://c.test/r.css') : null, new GoogleFonts()], true);

        self::assertStringContainsString('href="https://c.test/r.css"', $html);
        self::assertStringContainsString(self::INTER_LINK, $html);
    }

    public function test_face_answers_become_font_face_rules(): void
    {
        $src = static fn (FontRequest $r): FontAnswer => FontAnswer::faces(new FontFaceSrc(700, FontStyle::Italic, 'fonts/a.woff2', 'woff2'));
        $html = $this->html($this->doc(), [$src]);

        self::assertStringContainsString('@font-face{font-family:"Inter";src:url("fonts/a.woff2") format("woff2");font-weight:700;font-style:italic}', $html);
    }

    public function test_other_modes_unchanged(): void
    {
        $invoice = (require __DIR__ . '/../../../../examples/core/documents/invoice.php')->createdAt(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $link = static fn (FontFace $f): string => 'fonts/' . basename($f->path);

        self::assertSame('4193052e4f6ef10cf167ff31df4efa70', md5($invoice->toHtml(new HtmlOptions(fullPage: true))->bytes()));
        self::assertSame('18c65ea2e905339c897d452b02365c21', md5($invoice->toHtml(new HtmlOptions(fullPage: true, fontMode: FontMode::Embed))->bytes()));
        self::assertSame('5b287cc221c5f3df9769a3eaac9b5147', md5($invoice->toHtml(new HtmlOptions(fullPage: true, fontMode: FontMode::Link, fontUrl: $link))->bytes()));
    }
}
