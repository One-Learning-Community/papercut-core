<?php

declare(strict_types=1);

namespace Papercut\Style;

/**
 * A list item marker: numbered styles or a bullet glyph.
 */
enum ListMarker: string
{
    case Decimal = 'decimal';
    case LowerAlpha = 'lower-alpha';
    case UpperAlpha = 'upper-alpha';
    case LowerRoman = 'lower-roman';
    case UpperRoman = 'upper-roman';
    case Disc = 'disc';
    case Circle = 'circle';
    case Square = 'square';
    case None = 'none';

    /**
     * The marker text for item number $n. Alphabetic markers fall back to
     * decimal below 1, roman ones outside 1–3999 (the CSS counter-style range).
     */
    public function label(int $n): string
    {
        return match ($this) {
            self::Decimal => $n . '.',
            self::LowerAlpha => $n < 1 ? $n . '.' : self::alpha($n) . '.',
            self::UpperAlpha => $n < 1 ? $n . '.' : strtoupper(self::alpha($n)) . '.',
            self::LowerRoman => $n < 1 || $n > 3999 ? $n . '.' : self::roman($n) . '.',
            self::UpperRoman => $n < 1 || $n > 3999 ? $n . '.' : strtoupper(self::roman($n)) . '.',
            self::Disc => "\u{2022}",
            self::Circle => "\u{25E6}",
            self::Square => "\u{25AA}",
            self::None => '',
        };
    }

    /** Depth is 1-based and cycles every three levels. */
    public static function forDepth(bool $ordered, int $depth): self
    {
        $level = (max(1, $depth) - 1) % 3;
        return $ordered
            ? [self::Decimal, self::LowerAlpha, self::LowerRoman][$level]
            : [self::Disc, self::Circle, self::Square][$level];
    }

    /** Maps the HTML ol type attribute; anything else is null. */
    public static function fromHtmlType(string $type): ?self
    {
        return match ($type) {
            '1' => self::Decimal,
            'a' => self::LowerAlpha,
            'A' => self::UpperAlpha,
            'i' => self::LowerRoman,
            'I' => self::UpperRoman,
            default => null,
        };
    }

    public function isOrdered(): bool
    {
        return match ($this) {
            self::Decimal, self::LowerAlpha, self::UpperAlpha, self::LowerRoman, self::UpperRoman => true,
            default => false,
        };
    }

    private static function alpha(int $n): string
    {
        $out = '';
        while ($n > 0) {
            $n--;
            $out = chr(97 + $n % 26) . $out;
            $n = intdiv($n, 26);
        }
        return $out;
    }

    private static function roman(int $n): string
    {
        $out = '';
        foreach (['m' => 1000, 'cm' => 900, 'd' => 500, 'cd' => 400, 'c' => 100, 'xc' => 90, 'l' => 50, 'xl' => 40, 'x' => 10, 'ix' => 9, 'v' => 5, 'iv' => 4, 'i' => 1] as $sym => $v) {
            while ($n >= $v) {
                $out .= $sym;
                $n -= $v;
            }
        }
        return $out;
    }
}
