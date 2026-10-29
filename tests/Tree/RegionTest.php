<?php

declare(strict_types=1);

namespace Papercut\Tests\Tree;

use Papercut\Tree\Region;
use PHPUnit\Framework\TestCase;

final class RegionTest extends TestCase
{
    public function test_headers_and_footers_include_their_variants(): void
    {
        $headers = [Region::Header, Region::FirstHeader, Region::EvenHeader];
        $footers = [Region::Footer, Region::FirstFooter, Region::EvenFooter];
        foreach (Region::cases() as $region) {
            self::assertSame(in_array($region, $headers, true), $region->isHeader(), $region->name);
            self::assertSame(in_array($region, $footers, true), $region->isFooter(), $region->name);
        }
    }
}
