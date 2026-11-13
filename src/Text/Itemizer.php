<?php

declare(strict_types=1);

namespace Papercut\Text;

use Papercut\Math\Layout\Box;
use Papercut\Math\Math;
use Papercut\Math\Node\Node as MathNode;
use Papercut\Span;
use Papercut\Style\{ComputedStyle, StyleResolver, Theme, WhiteSpace};

/** @internal Part of Papercut's engine, not its public API. It may change in any release. */
final class Itemizer
{
    private const string BREAK_AFTER = "-\u{2013}\u{2014}/";

    /** @var array<string, true> */
    private array $warned = [];

    /** @var \SplObjectStorage<MathNode, array<string, Box>> each formula's boxes by the size, font and style they were laid out at */
    private \SplObjectStorage $mathBoxes;

    /** @param list<string> $fallbackFamilies */
    public function __construct(
        private readonly FontRegistry $fonts,
        private readonly ?\Closure $onWarning = null,
        private readonly array $fallbackFamilies = [],
        private readonly ?Theme $theme = null,
        /** The theme's resolver, which computes each distinct span style once; without one, every span computes its own. */
        private readonly ?StyleResolver $styles = null,
    ) {
        $this->mathBoxes = new \SplObjectStorage();
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
            $own = ($this->theme ?? Theme::default())->spanStyle($span);
            $style = $this->styles?->span($paragraph, $own) ?? $paragraph->child($own);
            $font = $this->primary($style);
            if ($span->lineBreak) {
                $pending = null;
                $items[] = new InlineItem('break', '', $font, $style->fontSize, 0.0, $style, $span->href);
                $atStart = true;
                continue;
            }
            if ($span->math !== null) {
                // An equation is one unbreakable word as wide as its formula; its text is empty, TextLayouter draws it.
                if ($pending !== null) {
                    $items[] = $pending;
                    $pending = null;
                }
                $box = $this->mathBox($span->math, $style, false);
                $items[] = new InlineItem('word', '', $font, $style->fontSize, $box->width, $style, $span->href, false, false, $box);
                $atStart = false;
                continue;
            }
            $text = self::drawable($span->text);
            if ($style->isInlineBox()) {
                $label = trim(preg_replace('/[ \t\n\r\f]+/', ' ', $text) ?? $text);
                if ($label !== '') {
                    // Spaces just inside the box space it from its neighbours, as in HTML; the label itself is trimmed.
                    if ($pending === null && !$atStart && preg_match('/^[ \t\n\r\f]/', $text) === 1) {
                        $pending = $this->space($paragraph, $this->primary($paragraph), $span->href);
                    }
                    if ($pending !== null) {
                        $items[] = $pending;
                        $pending = null;
                    }
                    $items[] = $this->box($label, $style, $font, $span->href);
                    $atStart = false;
                    if (preg_match('/[ \t\n\r\f]$/', $text) === 1) {
                        $pending = $this->space($paragraph, $this->primary($paragraph), $span->href);
                    }
                }
                continue;
            }
            if ($style->whiteSpace === WhiteSpace::PreWrap) {
                $own = $this->preWrap($span, $text, $style, $font);
                if ($own !== []) {
                    // A pending normal space is flushed only by a pre-wrap span that produced items.
                    if ($pending !== null) {
                        $items[] = $pending;
                        $pending = null;
                    }
                    array_push($items, ...$own);
                    $atStart = $own[count($own) - 1]->kind === 'break';
                }
                continue;
            }
            $parts = preg_split('/([ \t\n\r\f]+)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
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

    /**
     * The text split into runs by the font that draws each, as it is in a paragraph of this style: the style's own font
     * where it covers a character, else a fallback family, else that font again with a warning naming the missing glyphs.
     *
     * @return list<array{string, CachedFont}>
     */
    public function runsFor(string $text, ComputedStyle $style): array
    {
        return $this->fontRuns($text, $style, $this->primary($style));
    }

    /**
     * A formula laid out with the math font at the style's size, display or text style. A character the math font lacks
     * is drawn in the face a paragraph would use (see MathPainter), so it takes that face's width. The script-size
     * alternates are off, because the PDF can only draw glyphs that have a code point.
     */
    public function mathBox(MathNode $math, ComputedStyle $style, bool $display): Box
    {
        $key = $style->fontSize . '|' . $style->fontFamily . '|' . $style->fontWeight . '|' . ($style->italic ? 'i' : 'n') . '|' . ($display ? 'd' : 't');
        $cached = $this->mathBoxes[$math] ?? [];
        if (isset($cached[$key])) {
            return $cached[$key];
        }
        $fallbackAdvance = function (string $char, float $size) use ($style): ?float {
            foreach ($this->runsFor($char, $style) as [$text, $face]) {
                return $face->covers($text) ? $face->advance($text, $size) : null;
            }
            return null;
        };
        $box = Math::layout($math, $style->fontSize, $display, $this->fonts->mathFont()['font'], false, $fallbackAdvance);
        $cached[$key] = $box;
        $this->mathBoxes[$math] = $cached;

        return $box;
    }

    private function space(ComputedStyle $style, CachedFont $font, ?string $href): InlineItem
    {
        return new InlineItem('space', ' ', $font, $style->fontSize, $font->advance(' ', $style->fontSize, $style->letterSpacing), $style, $href);
    }

    /** An inline box's label as one unbreakable item as wide as its text plus its padding and border. */
    private function box(string $label, ComputedStyle $style, CachedFont $primary, ?string $href): InlineItem
    {
        $font = $primary->covers($label) ? $primary : $this->fallback($label, $style, $primary);
        return new InlineItem(
            'word',
            $label,
            $font,
            $style->fontSize,
            $font->advance($label, $style->fontSize, $style->letterSpacing) + $style->padding->horizontal() + $style->borderWidth->horizontal(),
            $style,
            $href,
            false,
            true,
        );
    }

    /**
     * Under pre-wrap every space is an item of its own, a newline is a break and a tab is a tab item.
     *
     * @return list<InlineItem>
     */
    private function preWrap(Span $span, string $text, ComputedStyle $style, CachedFont $font): array
    {
        $space = $font->advance(' ', $style->fontSize, $style->letterSpacing);
        $out = [];
        $parts = preg_split('/(\r\n|\r|\n|\t|[ \f])/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        foreach ($parts === false ? [] : $parts as $part) {
            array_push($out, ...match (true) {
                $part === "\t" => [new InlineItem('tab', "\t", $font, $style->fontSize, 4 * $space, $style, $span->href)],
                $part === ' ' || $part === "\f" => [new InlineItem('space', ' ', $font, $style->fontSize, $space, $style, $span->href)],
                trim($part, "\r\n") === '' => [new InlineItem('break', '', $font, $style->fontSize, 0.0, $style, $span->href)],
                default => $this->words($part, $style, $font, $span->href),
            });
        }
        return $out;
    }

    /**
     * The text as drawn: invisible formatting characters (soft hyphens, zero-width joiners and spaces, direction
     * marks, word joiners, byte order marks) have no glyph and would print as empty boxes, so they are left out.
     * The line and paragraph separators are newlines.
     */
    private static function drawable(string $text): string
    {
        if (preg_match('/[^\x00-\x7F]/', $text) !== 1) {
            return $text;
        }
        $text = str_replace(["\u{2028}", "\u{2029}"], "\n", $text);
        return preg_replace('/\p{Cf}+/u', '', $text) ?? $text;
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
        // Plain ASCII without a break-after character is one segment: no CJK, nowhere to break.
        if (preg_match('~[^\x00-\x7F]|[-/]~', $word) !== 1) {
            return [[$word, false]];
        }
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
