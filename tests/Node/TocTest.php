<?php

declare(strict_types=1);

namespace Papercut\Tests\Node;

use Papercut\Exception\InvalidValue;
use Papercut\Style\{ComputedStyle, Style, StyleResolver, Theme};
use Papercut\{Text, Toc};
use PHPUnit\Framework\TestCase;

final class TocTest extends TestCase
{
    public function test_defaults(): void
    {
        $t = Toc::make();
        self::assertSame([1, 2, null, true, 'toc'], [$t->fromLevel(), $t->toLevel(), $t->titleText(), $t->hasLeaders(), $t->debugName()]);
    }

    public function test_levels_are_validated(): void
    {
        foreach ([[0, 2], [3, 2], [1, 5]] as [$from, $to]) {
            try {
                Toc::make()->levels($from, $to);
                self::fail("levels($from, $to) should throw");
            } catch (InvalidValue) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_options_are_immutable(): void
    {
        $a = Toc::make();
        $b = $a->levels(1, 3)->title('Contents')->leaders(false);
        self::assertNotSame($a, $b);
        self::assertSame([1, 3, 'Contents', false], [$b->fromLevel(), $b->toLevel(), $b->titleText(), $b->hasLeaders()]);
        self::assertSame([1, 2, null, true], [$a->fromLevel(), $a->toLevel(), $a->titleText(), $a->hasLeaders()]);
    }

    public function test_toc_style_property(): void
    {
        $resolver = new StyleResolver(Theme::default());
        $root = $resolver->resolve(Text::make('root'), ComputedStyle::root(Theme::default()));
        self::assertSame(false, $resolver->resolve(Text::h2('x')->toc(false), $root)->toc);
        self::assertNull($resolver->resolve(Text::h2('x'), $root)->toc);
        self::assertFalse(Style::fromAttributes(['toc' => 'false'])->toc);
    }

    public function test_a_blank_title_is_no_title(): void
    {
        foreach (['', '   ', "\n\t"] as $blank) {
            self::assertNull(Toc::make()->title('Contents')->title($blank)->titleText());
        }
        self::assertNull(Toc::make()->title(null)->titleText());
        self::assertSame('Contents', Toc::make()->title('Contents')->titleText());
    }
}
