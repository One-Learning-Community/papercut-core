<?php

declare(strict_types=1);

namespace Quoin\Tests\Markup;

use Quoin\Export\{HtmlOptions, PdfOptions};
use Quoin\Markup\{Markup, MarkupOptions, StyleMode};
use Quoin\Tests\Support\FailsOnDiagnostics;
use Quoin\{Document, Page};
use PHPUnit\Framework\TestCase;

/** An iframe, video or audio src with a control character is dropped with a note; markup never throws (D1). */
final class EmbedSrcControlCharactersTest extends TestCase
{
    use FailsOnDiagnostics;

    public function test_a_src_with_a_newline_is_dropped_not_thrown(): void
    {
        foreach (['iframe', 'video', 'audio'] as $tag) {
            foreach ([StyleMode::Theme, StyleMode::Original] as $mode) {
                $html = "<p>before</p><$tag src=\"https://a.test/x&#10;y\" title=\"t\"></$tag><p>after</p>";
                $doc = Document::make(Page::a4()->body(Markup::html($html, new MarkupOptions(styles: $mode))));
                $notes = [];
                $doc->toPdf(new PdfOptions(onWarning: static function (string $m) use (&$notes): void {
                    $notes[] = $m;
                }, cacheDir: false));
                $dropped = array_filter($notes, static fn (string $n): bool => str_contains($n, "<$tag>") && str_contains($n, 'removed'));
                self::assertCount(1, $dropped, "$tag: " . implode("\n", $notes));
                self::assertStringContainsString('<p', $doc->toHtml(new HtmlOptions())->bytes());
            }
        }
    }

    public function test_a_clean_src_is_still_shown_as_a_link(): void
    {
        $doc = Document::make(Page::a4()->body(Markup::html('<iframe src=" https://a.test/x " title="t"></iframe>')));
        $notes = [];
        $doc->toPdf(new PdfOptions(onWarning: static function (string $m) use (&$notes): void {
            $notes[] = $m;
        }, cacheDir: false));
        self::assertCount(1, $notes);
        self::assertStringContainsString('shown as a link', $notes[0]);
    }
}
