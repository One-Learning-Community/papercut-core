<?php

declare(strict_types=1);

namespace Papercut\Tests\Text;

use Papercut\Tests\Support\FakeFont;
use Papercut\Text\CachedFont;
use PHPUnit\Framework\TestCase;

final class CachedFontTest extends TestCase
{
    public function test_caches_and_scales(): void
    {
        $inner = new FakeFont();
        $f = new CachedFont($inner);
        self::assertSame(6.0, $f->advance('ab', 6));
        self::assertSame(12.0, $f->advance('ab', 12));
        self::assertSame(24.0, $f->advance('ab', 24));
        self::assertSame(1, $inner->advanceCalls);
    }

    public function test_letter_spacing_bypasses_scaled_cache_but_is_cached_itself(): void
    {
        $inner = new FakeFont();
        $f = new CachedFont($inner);
        self::assertSame(14.0, $f->advance('ab', 12, 1.0));
        self::assertSame(14.0, $f->advance('ab', 12, 1.0));
        self::assertSame(1, $inner->advanceCalls);
    }

    public function test_spaced_cache_distinguishes_size_and_spacing(): void
    {
        $inner = new FakeFont();
        $f = new CachedFont($inner);
        $f->advance('ab', 12, 1.0);
        $f->advance('ab', 12, 2.0);
        $f->advance('ab', 14, 1.0);
        self::assertSame(3, $inner->advanceCalls);
    }

    public function test_zero_and_nonzero_spacing_do_not_share_an_entry(): void
    {
        $inner = new FakeFont();
        $f = new CachedFont($inner);
        self::assertSame(12.0, $f->advance('ab', 12));
        self::assertSame(14.0, $f->advance('ab', 12, 1.0));
        self::assertSame(2, $inner->advanceCalls);
    }

    public function test_delegates(): void
    {
        $inner = new FakeFont();
        $f = new CachedFont($inner);
        self::assertSame($inner, $f->inner());
        self::assertSame('fake', $f->id());
        self::assertSame(8.0, $f->ascent(10));
        self::assertSame(2.0, $f->descent(10));
        self::assertFalse($f->covers('漢'));
    }
}
