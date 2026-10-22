<?php

declare(strict_types=1);

namespace Papercut\Text;

use Papercut\Span;
use Papercut\Style\ComputedStyle;

final class Itemizer
{
    private const string BREAK_AFTER = "-\u{2013}\u{2014}/";

    /** @var array<string, true> */
    private array $warned = [];

    /** @param list<string> $fallbackFamilies */
    public function __construct(
        private readonly FontRegistry $fonts,
        private readonly ?\Closure $onWarning = null,
        private readonly array $fallbackFamilies = [],
    ) {
    }

    /**
     * @param list<Span> $spans
     * @return list<InlineItem>
     */
    public function itemize(array $spans, ComputedStyle $paragraph): array
    {
        /** @var list<InlineItem> $items */
        $items = [];
        /** @var ?InlineItem $pending */
        $pending = null;
        $atStart = true;

        foreach ($spans as $span) {
            $style = $paragraph->child($span->style);
            $font = $this->primary($style);
            if ($span->lineBreak) {
                $pending = null;
                $items[] = new InlineItem('break', '', $font, $style->fontSize, 0.0, $style, $span->href);
                $atStart = true;
                continue;
            }
            $parts = preg_split('/([ \t\n\r\f]+)/', $span->text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
            foreach ($parts === false ? [] : $parts as $part) {
                if (preg_match('/^[ \t\n\r\f]+$/', $part) === 1) {
                    if (!$atStart && $pending === null) {
                        $pending = new InlineItem(
                            'space',
                            ' ',
                            $font,
                            $style->fontSize,
                            $font->advance(' ', $style->fontSize, $style->letterSpacing),
                            $style,
                            $span->href,
                        );
                    }
                    continue;
                }
                if ($pending !== null) {
                    $items[] = $pending;
                    $pending = null;
                }
                foreach ($this->words($part, $style, $font, $span->href) as $w) {
                    $items[] = $w;
                }
                $atStart = false;
            }
        }
        return $items;
    }

    private function primary(ComputedStyle $style): CachedFont
    {
        return $this->fonts->resolve($style->fontFamily, $style->fontWeight, $style->italic ? FontStyle::Italic : FontStyle::Normal);
    }

    /** @return list<InlineItem> */
    private function words(string $text, ComputedStyle $style, CachedFont $font, ?string $href): array
    {
        $out = [];
        foreach ($this->segments($text) as [$segment, $breakAfter]) {
            $runs = $this->fontRuns($segment, $style, $font);
            foreach ($runs as $i => [$run, $runFont]) {
                $out[] = new InlineItem(
                    'word',
                    $run,
                    $runFont,
                    $style->fontSize,
                    $runFont->advance($run, $style->fontSize, $style->letterSpacing),
                    $style,
                    $href,
                    $breakAfter && $i === count($runs) - 1,
                );
            }
        }
        return $out;
    }

    /** @return list<array{string, bool}> */
    private function segments(string $word): array
    {
        $chars = mb_str_split($word, 1, 'UTF-8');
        $segments = [];
        $buf = '';
        $n = count($chars);
        foreach ($chars as $i => $ch) {
            if (self::isCjk($ch)) {
                if ($buf !== '') {
                    $segments[] = [$buf, false];
                    $buf = '';
                }
                $segments[] = [$ch, true];
                continue;
            }
            $buf .= $ch;
            if (str_contains(self::BREAK_AFTER, $ch)) {
                $next = $chars[$i + 1] ?? null;
                if ($next === null || !str_contains(self::BREAK_AFTER, $next)) {
                    $segments[] = [$buf, true];
                    $buf = '';
                }
            } elseif ($i === $n - 1) {
                $segments[] = [$buf, false];
                $buf = '';
            }
        }
        if ($buf !== '') {
            $segments[] = [$buf, false];
        }
        return $segments;
    }

    private static function isCjk(string $ch): bool
    {
        $c = mb_ord($ch, 'UTF-8');
        return ($c >= 0x3040 && $c <= 0x30FF) || ($c >= 0x3400 && $c <= 0x9FFF) || ($c >= 0xF900 && $c <= 0xFAFF);
    }

    /** @return list<array{string, CachedFont}> */
    private function fontRuns(string $segment, ComputedStyle $style, CachedFont $primary): array
    {
        if ($primary->covers($segment)) {
            return [[$segment, $primary]];
        }
        /** @var list<array{string, bool}> $groups */
        $groups = [];
        foreach (mb_str_split($segment, 1, 'UTF-8') as $ch) {
            $covered = $primary->covers($ch);
            $last = count($groups) - 1;
            if ($last >= 0 && $groups[$last][1] === $covered) {
                $groups[$last][0] .= $ch;
            } else {
                $groups[] = [$ch, $covered];
            }
        }
        $runs = [];
        foreach ($groups as [$run, $covered]) {
            $runs[] = [$run, $covered ? $primary : $this->fallback($run, $style, $primary)];
        }
        return $runs;
    }

    private function fallback(string $run, ComputedStyle $style, CachedFont $primary): CachedFont
    {
        $fontStyle = $style->italic ? FontStyle::Italic : FontStyle::Normal;
        foreach ($this->fallbackFamilies as $family) {
            if (!$this->fonts->has($family)) {
                continue;
            }
            $candidate = $this->fonts->resolve($family, $style->fontWeight, $fontStyle);
            if ($candidate->covers($run)) {
                return $candidate;
            }
        }
        $key = $style->fontFamily . "\0" . $run;
        if (!isset($this->warned[$key])) {
            $this->warned[$key] = true;
            if ($this->onWarning !== null) {
                ($this->onWarning)(sprintf('Missing glyphs for "%s" in font family %s', $run, $style->fontFamily));
            }
        }
        return $primary;
    }
}
