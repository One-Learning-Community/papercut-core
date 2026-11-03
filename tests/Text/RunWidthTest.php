<?php

declare(strict_types=1);

namespace Papercut\Tests\Text;

use Papercut\Export\PdfOptions;
use Papercut\Text\{FontRegistry, FontStyle};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A merged run's width is its items' widths plus what each join adds, measured from the characters that meet (for fonts
 * that join in pairs, as the bundled ones do; TextLayouter also measures a space with both its neighbours).
 * TextLayouter relies on this to place merged runs without shaping each whole run again.
 */
final class RunWidthTest extends TestCase
{
    /** @return iterable<string, array{string, int, FontStyle, float, list<string>}> */
    public static function runs(): iterable
    {
        $kerning = ['AV', ' ', 'To', ' ', 'Wave', ' ', 'Ty.', ' ', 'LT', ' ', 'P,', ' ', 'Yo', ' ', '"Fi"'];
        yield 'regular kerning pairs' => ['Inter', 400, FontStyle::Normal, 0.0, $kerning];
        yield 'bold' => ['Inter', 700, FontStyle::Normal, 0.0, $kerning];
        yield 'italic' => ['Inter', 400, FontStyle::Italic, 0.0, $kerning];
        yield 'letter spacing' => ['Inter', 400, FontStyle::Normal, 1.5, $kerning];
        yield 'ligature candidates and hyphen splits' => ['Inter', 400, FontStyle::Normal, 0.0, ['office', ' ', 'waffle', ' ', 'well-', 'known', ' ', 'and/', 'or']];
        yield 'accents and quotes' => ['Inter', 400, FontStyle::Normal, 0.0, ['Café', ' ', '“Ýou”', ' ', 'naïve', ' ', 'Ångström']];
        yield 'monospace' => ['Cousine', 400, FontStyle::Normal, 0.0, ['AV', ' ', 'To', ' ', 'fi']];
    }

    /** @param list<string> $items */
    #[DataProvider('runs')]
    public function test_a_run_is_its_items_plus_their_boundaries(string $family, int $weight, FontStyle $style, float $spacing, array $items): void
    {
        $options = new PdfOptions();
        $font = FontRegistry::fromSet($options->fonts(), $options->backend())->resolve($family, $weight, $style);
        $size = 11.0;
        self::assertTrue($font->joinsInPairs());

        $parts = 0.0;
        $prev = null;
        foreach ($items as $item) {
            $parts += $font->advance($item, $size, $spacing);
            if ($prev !== null) {
                $a = mb_substr($prev, -1, 1, 'UTF-8');
                $b = mb_substr($item, 0, 1, 'UTF-8');
                $parts += $font->advance($a . $b, $size, $spacing) - $font->advance($a, $size, $spacing) - $font->advance($b, $size, $spacing);
            }
            $prev = $item;
        }

        self::assertEqualsWithDelta($font->advance(implode('', $items), $size, $spacing), $parts, 1e-9);
    }
}
