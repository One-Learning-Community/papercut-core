<?php

declare(strict_types=1);

namespace Papercut\Tests\Style;

use PHPUnit\Framework\TestCase;
use Papercut\{Document, Page};
use Papercut\Style\{PageThemes, Theme, ThemeOverride};
use Papercut\Value\Color;

use function Papercut\pt;

final class PageThemesTest extends TestCase
{
    public function test_pages_without_an_override_share_the_document_theme_and_resolver(): void
    {
        $a = Page::a4();
        $b = Page::a4()->theme(new ThemeOverride(fontSize: pt(14)));
        $themes = PageThemes::for(Theme::default(), Document::make($a, $b)->theme(new ThemeOverride(fontSize: pt(11))));
        self::assertSame($themes->document(), $themes->theme($a));
        self::assertSame($themes->documentStyles(), $themes->styles($a));
        self::assertSame(11.0, $themes->theme($a)->fontSize);
        self::assertSame(14.0, $themes->theme($b)->fontSize);
        self::assertSame($themes->theme($b), $themes->theme($b));
        self::assertSame($themes->styles($b), $themes->styles($b));
    }

    public function test_page_override_merges_onto_document_override(): void
    {
        $doc = Document::make($p = Page::a4()->theme(new ThemeOverride(color: Color::parse('red-500'))))
            ->theme(new ThemeOverride(fontSize: pt(11)));
        $t = PageThemes::for(Theme::default(), $doc)->theme($p);
        self::assertSame(11.0, $t->fontSize);
        self::assertEquals(Color::parse('red-500'), $t->color);
    }

    public function test_no_overrides_gives_the_base_theme_itself(): void
    {
        $base = Theme::default();
        $p = Page::a4();
        $themes = PageThemes::for($base, Document::make($p));
        self::assertSame($base, $themes->document());
        self::assertSame($base, $themes->theme($p));
    }

    public function test_theme_called_twice_merges(): void
    {
        $p = Page::a4()->theme(new ThemeOverride(fontSize: pt(11)))->theme(new ThemeOverride(color: Color::parse('red-500')));
        $o = $p->themeOverride();
        self::assertNotNull($o);
        self::assertSame(11.0, Theme::default()->withOverride($o)->fontSize);
        $d = Document::make($p)->theme(new ThemeOverride(fontSize: pt(9)))->theme(new ThemeOverride(lineHeight: 2.0));
        $do = $d->themeOverride();
        self::assertNotNull($do);
        self::assertSame(9.0, $do->fontSize?->points);
        self::assertSame(2.0, $do->lineHeight);
        self::assertNull(Page::a4()->themeOverride());
    }
}
