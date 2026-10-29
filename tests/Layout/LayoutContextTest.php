<?php

declare(strict_types=1);

namespace Papercut\Tests\Layout;

use Papercut\{OverflowMode, Text};
use Papercut\Layout\LayoutContext;
use Papercut\Style\{Style, Theme};
use Papercut\Text\FontRegistry;
use Papercut\Tests\Support\FakeBackend;
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

final class LayoutContextTest extends TestCase
{
    private static function ctx(): LayoutContext
    {
        $backend = new FakeBackend();
        return new LayoutContext(FontRegistry::withDefaults($backend), Theme::default(), $backend, OverflowMode::Throw, null);
    }

    public function test_warn_once_reports_a_message_once_per_path(): void
    {
        $got = [];
        $backend = new FakeBackend();
        $ctx = new LayoutContext(FontRegistry::withDefaults($backend), Theme::default(), $backend, OverflowMode::Throw, function (string $m) use (&$got): void { $got[] = $m; });
        $ctx->warnOnce('same');
        $ctx->warnOnce('same');
        $ctx->pushPath('box');
        $ctx->warnOnce('same');
        $ctx->warnOnce('same');
        $ctx->warn('same');
        self::assertSame(['same', '[box] same', '[box] same'], $got, 'warn() still reports every time');
    }

    public function test_resolve_style_is_memoized_per_node_and_parent(): void
    {
        $ctx = self::ctx();
        $node = Text::make('x')->bold();
        $parent = $ctx->rootStyle();
        $first = $ctx->resolveStyle($node, $parent);
        self::assertSame($first, $ctx->resolveStyle($node, $parent));
        self::assertSame(700, $first->fontWeight);
    }

    public function test_resolve_style_keys_on_the_parent_style(): void
    {
        $ctx = self::ctx();
        $node = Text::make('x');
        $root = $ctx->rootStyle();
        $big = $root->child(new Style(fontSize: pt(30)));
        $small = $root->child(new Style(fontSize: pt(6)));
        self::assertSame(30.0, $ctx->resolveStyle($node, $big)->fontSize);
        self::assertSame(6.0, $ctx->resolveStyle($node, $small)->fontSize);
        self::assertSame(30.0, $ctx->resolveStyle($node, $big)->fontSize);
    }

    public function test_resolve_style_keys_on_node_identity(): void
    {
        $ctx = self::ctx();
        $plain = Text::make('x');
        $bold = $plain->bold();
        $root = $ctx->rootStyle();
        self::assertSame(400, $ctx->resolveStyle($plain, $root)->fontWeight);
        self::assertSame(700, $ctx->resolveStyle($bold, $root)->fontWeight);
    }

    public function test_caches_do_not_cross_contexts(): void
    {
        $a = self::ctx();
        $b = self::ctx();
        $node = Text::make('x');
        self::assertNotSame($a->rootStyle(), $b->rootStyle());
        self::assertNotSame($a->resolveStyle($node, $a->rootStyle()), $b->resolveStyle($node, $a->rootStyle()));
    }

    public function test_root_style_is_computed_once_per_context(): void
    {
        $ctx = self::ctx();
        self::assertSame($ctx->rootStyle(), $ctx->rootStyle());
        self::assertSame(Theme::default()->fontSize, $ctx->rootStyle()->fontSize);
    }
}
