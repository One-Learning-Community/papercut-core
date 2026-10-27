<?php

declare(strict_types=1);

namespace Papercut\Vector;

use Papercut\Exception\ImageUnreadable;

/**
 * An SVG source, parsed and checked once: its size, view box and alignment, and the cleaned-up
 * XML the renderers draw from.
 *
 * Untrusted SVG is safe to hand over. The XML parser never loads external files or DTDs, a
 * document that declares entities is refused, and so is one that would take too long to draw:
 * over MAX_BYTES, or over MAX_WORK points drawn once every <use> is expanded.
 * Scripts and on* event handlers are removed.
 */
final readonly class SvgDocument
{
    /** The largest source accepted, in bytes. libxml refuses a single attribute over 10 MB anyway. */
    public const int MAX_BYTES = 10 * 1024 * 1024;

    /**
     * The most drawing work accepted, with every <use> expanded, in units of about 4 µs of
     * drawing: one per path or polygon point, 15 per circle or ellipse and 5 for any other
     * element. A million takes about a second and a half and 50 MB (PHP 8.4, no debugger).
     */
    public const int MAX_WORK = 1_000_000;

    private const int ELEMENT_WORK = 5;
    private const int OUTLINE_WORK = 15;

    /** The deepest chain of <use> elements referring to one another. */
    private const int MAX_USE_DEPTH = 32;

    private const float PT_PER_PX = 0.75;

    /** Elements the renderer skips, with their content. */
    private const array UNSUPPORTED = ['a', 'clipPath', 'filter', 'foreignObject', 'image', 'marker', 'mask', 'pattern', 'svg', 'switch', 'symbol', 'textPath', 'tspan'];

    /**
     * @param array{float, float, float, float} $viewBox min-x, min-y, width, height in user units
     * @param list<string> $unsupported elements found that the renderer skips, like "<clipPath>"
     */
    private function __construct(
        public string $xml,
        public float $widthPt,
        public float $heightPt,
        public array $viewBox,
        public string $align,
        public bool $slice,
        public string $title,
        public bool $hasText,
        public array $unsupported,
    ) {
    }

    /** @throws ImageUnreadable when the source is not SVG, is unsafe, or is too large to draw */
    public static function parse(string $source): self
    {
        if (trim($source) === '') {
            throw new ImageUnreadable('Cannot read SVG: the source is empty');
        }
        if (strlen($source) > self::MAX_BYTES) {
            throw new ImageUnreadable(sprintf('Cannot read SVG: it is larger than %d MB', self::MAX_BYTES / 1024 / 1024));
        }

        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            // No LIBXML_NOENT or LIBXML_DTDLOAD: entities stay unexpanded and no DTD is fetched.
            $loaded = $dom->loadXML($source, LIBXML_NONET);
            $error = libxml_get_last_error();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded || $dom->documentElement === null) {
            $where = $error instanceof \LibXMLError ? sprintf(' (line %d: %s)', $error->line, trim($error->message)) : '';
            throw new ImageUnreadable('Cannot read SVG: it is not well-formed XML' . $where);
        }
        if ($dom->doctype !== null) {
            if ($dom->doctype->entities->length > 0 || trim((string) $dom->doctype->internalSubset) !== '') {
                throw new ImageUnreadable('Cannot read SVG: it declares entities in its DOCTYPE, which Papercut refuses for safety');
            }
            $dom->removeChild($dom->doctype);
        }

        $root = $dom->documentElement;
        if ($root->localName !== 'svg') {
            throw new ImageUnreadable(sprintf('Cannot read SVG: the root element is <%s>, not <svg>', $root->localName));
        }

        if (self::work($root, self::ids($dom), [], 0) > self::MAX_WORK) {
            throw new ImageUnreadable(sprintf('Cannot read SVG: it is too complex to draw in a few seconds (more than %s units of work, counting each <use>: 1 per path point, 15 per circle, 5 per other element)', number_format(self::MAX_WORK)));
        }

        [$widthPt, $heightPt, $viewBox] = self::size($root);
        [$align, $slice] = self::aspect($root->getAttribute('preserveAspectRatio'));

        // Nothing draws from scripts or event handlers; dropping them keeps the HTML copy inert too.
        self::stripScripts($root);

        // The renderers get an explicit view box and size, so PDF and a browser scale it alike.
        $root->setAttribute('viewBox', implode(' ', array_map(self::number(...), $viewBox)));
        $root->setAttribute('width', self::number($widthPt) . 'pt');
        $root->setAttribute('height', self::number($heightPt) . 'pt');

        $title = '';
        $hasText = false;
        $unsupported = [];
        foreach ($root->getElementsByTagName('*') as $el) {
            $name = $el->localName;
            if ($name === 'title' && $title === '' && $el->parentNode === $root) {
                $title = trim((string) preg_replace('/\s+/u', ' ', $el->textContent));
            }
            $hasText = $hasText || $name === 'text';
            if (in_array($name, self::UNSUPPORTED, true)) {
                $unsupported['<' . $name . '>'] = true;
            }
        }
        $unsupported = array_keys($unsupported);
        sort($unsupported);

        $xml = $dom->saveXML($root);
        if ($xml === false) {
            throw new ImageUnreadable('Cannot read SVG: it could not be serialised again');
        }

        return new self($xml, $widthPt, $heightPt, $viewBox, $align, $slice, $title, $hasText, $unsupported);
    }

    private static function stripScripts(\DOMElement $root): void
    {
        foreach (iterator_to_array($root->getElementsByTagName('script')) as $script) {
            $script->parentNode?->removeChild($script);
        }
        foreach ([$root, ...iterator_to_array($root->getElementsByTagName('*'))] as $el) {
            foreach (iterator_to_array($el->attributes ?? []) as $attribute) {
                if (str_starts_with(strtolower($attribute->name), 'on')) {
                    $el->removeAttributeNode($attribute);
                }
            }
        }
    }

    /** @return array<string, \DOMElement> */
    private static function ids(\DOMDocument $dom): array
    {
        $ids = [];
        foreach ($dom->getElementsByTagName('*') as $el) {
            $id = $el->getAttribute('id');
            if ($id !== '') {
                $ids[$id] ??= $el;
            }
        }
        return $ids;
    }

    /**
     * The points drawn for $el and its children (see MAX_WORK), with each <use> counted as a
     * copy of what it refers to. Stops counting once over the limit, so a crafted file costs
     * little to refuse.
     *
     * @param array<string, \DOMElement> $ids
     * @param array<string, true> $using the <use> targets being expanded, to catch cycles
     */
    private static function work(\DOMElement $el, array $ids, array $using, int $depth): int
    {
        $work = in_array($el->localName, ['circle', 'ellipse'], true) ? self::OUTLINE_WORK : self::ELEMENT_WORK;
        foreach (['d', 'points'] as $attribute) {
            if ($el->hasAttribute($attribute)) {
                $work += intdiv((int) preg_match_all('/[-+]?(?:\d+\.?\d*|\.\d+)/', $el->getAttribute($attribute)), 2);
            }
        }
        if ($el->localName === 'use') {
            $href = $el->getAttribute('href');
            if ($href === '') {
                $href = $el->getAttributeNS('http://www.w3.org/1999/xlink', 'href');
            }
            $target = str_starts_with($href, '#') ? ($ids[substr($href, 1)] ?? null) : null;
            if ($target !== null) {
                $key = $target->getNodePath() ?? $href;
                if (isset($using[$key]) || self::contains($target, $el)) {
                    throw new ImageUnreadable(sprintf('Cannot read SVG: a <use> refers to itself through "%s"', $href));
                }
                if ($depth >= self::MAX_USE_DEPTH) {
                    throw new ImageUnreadable(sprintf('Cannot read SVG: <use> elements nest more than %d deep', self::MAX_USE_DEPTH));
                }
                $work += self::work($target, $ids, $using + [$key => true], $depth + 1);
            }
        }
        foreach ($el->childNodes as $child) {
            if ($work > self::MAX_WORK) {
                break;
            }
            if ($child instanceof \DOMElement) {
                $work += self::work($child, $ids, $using, $depth);
            }
        }
        return $work;
    }

    private static function contains(\DOMElement $ancestor, \DOMNode $node): bool
    {
        for ($n = $node; $n !== null; $n = $n->parentNode) {
            if ($n === $ancestor) {
                return true;
            }
        }
        return false;
    }

    /**
     * Size in points and the view box. Without a view box, user units are pixels of the given
     * size. A missing or relative side comes from the view box's aspect ratio, else from the
     * 300 x 150 pixels a browser uses.
     *
     * @return array{float, float, array{float, float, float, float}}
     */
    private static function size(\DOMElement $root): array
    {
        $w = self::length($root->getAttribute('width'));
        $h = self::length($root->getAttribute('height'));
        $box = self::viewBox($root->getAttribute('viewBox'));

        if ($box !== null) {
            [, , $bw, $bh] = $box;
            if ($w === null && $h === null) {
                $w = $bw * self::PT_PER_PX;
                $h = $bh * self::PT_PER_PX;
            } elseif ($w === null) {
                $w = (float) $h * $bw / $bh;
            } elseif ($h === null) {
                $h = $w * $bh / $bw;
            }
            return [$w, $h, $box];
        }

        $w ??= 300 * self::PT_PER_PX;
        $h ??= 150 * self::PT_PER_PX;
        return [$w, $h, [0.0, 0.0, $w / self::PT_PER_PX, $h / self::PT_PER_PX]];
    }

    /** An absolute length in points, or null for a missing, relative or non-positive one. */
    private static function length(string $value): ?float
    {
        if (preg_match('/^\s*([+-]?(?:\d+\.?\d*|\.\d+)(?:e[+-]?\d+)?)\s*(px|pt|pc|mm|cm|in)?\s*$/i', $value, $m) !== 1) {
            return null;
        }
        $points = (float) $m[1] * match (strtolower($m[2] ?? '')) {
            'pt' => 1.0,
            'pc' => 12.0,
            'in' => 72.0,
            'cm' => 72 / 2.54,
            'mm' => 72 / 25.4,
            default => self::PT_PER_PX,
        };
        return $points > 0.0 && is_finite($points) ? $points : null;
    }

    /** @return array{float, float, float, float}|null */
    private static function viewBox(string $value): ?array
    {
        $parts = preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false || count($parts) !== 4) {
            return null;
        }
        foreach ($parts as $part) {
            if (!is_numeric($part)) {
                return null;
            }
        }
        $box = array_map(static fn (string $p): float => (float) $p, $parts);
        if ($box[2] <= 0.0 || $box[3] <= 0.0) {
            return null;
        }
        return [$box[0], $box[1], $box[2], $box[3]];
    }

    /** @return array{string, bool} the alignment ("none" or like "xMidYMid") and whether it slices */
    private static function aspect(string $value): array
    {
        $words = preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (($words[0] ?? '') === 'defer') {
            array_shift($words);
        }
        $align = $words[0] ?? 'xMidYMid';
        if ($align !== 'none' && preg_match('/^x(Min|Mid|Max)Y(Min|Mid|Max)$/', $align) !== 1) {
            return ['xMidYMid', false];
        }
        return [$align, ($words[1] ?? 'meet') === 'slice'];
    }

    private static function number(float $n): string
    {
        $s = rtrim(rtrim(sprintf('%.6F', $n), '0'), '.');
        return $s === '-0' ? '0' : $s;
    }
}
