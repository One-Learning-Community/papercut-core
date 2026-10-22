<?php

declare(strict_types=1);

namespace Papercut\Tests\Export;

use Papercut\Export\{ExportOptions, FontMode, HtmlOptions};
use Papercut\Style\Theme;
use Papercut\Text\FontSet;
use PHPUnit\Framework\TestCase;

final class HtmlOptionsTest extends TestCase
{
    public function test_defaults(): void
    {
        $o = new HtmlOptions();
        self::assertFalse($o->fullPage());
        self::assertSame(FontMode::None, $o->fontMode());
        self::assertNull($o->imageUrl());
        self::assertNull($o->fontUrl());
        self::assertFalse($o->strict());
    }

    public function test_from_copies_shared_fields_and_keeps_html_options(): void
    {
        $theme = Theme::default();
        $fonts = new FontSet();
        $warn = static function (string $m): void {
        };
        $o = HtmlOptions::from(new ExportOptions($theme, $fonts, $warn, true));
        self::assertSame($theme, $o->theme());
        self::assertSame($fonts, $o->fonts());
        self::assertSame($warn, $o->onWarning());
        self::assertTrue($o->strict());
        self::assertFalse($o->fullPage());
        self::assertSame(FontMode::None, $o->fontMode());

        $html = new HtmlOptions(fullPage: true, fontMode: FontMode::Embed);
        self::assertSame($html, HtmlOptions::from($html));
    }

    public function test_with_full_page_copies_everything(): void
    {
        $img = static fn ($i): ?string => null;
        $o = new HtmlOptions(strict: true, fontMode: FontMode::Link, imageUrl: $img);
        $p = $o->withFullPage(true);
        self::assertTrue($p->fullPage());
        self::assertFalse($o->fullPage());
        self::assertTrue($p->strict());
        self::assertSame(FontMode::Link, $p->fontMode());
        self::assertSame($img, $p->imageUrl());
    }
}
