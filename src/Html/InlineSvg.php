<?php

declare(strict_types=1);

namespace Papercut\Html;

/**
 * Writes SVG markup from a trusted source as an inline element in an HTML page. Every id is prefixed and every reference
 * to it rewritten, and style rules are scoped to the SVG's own root, so two SVGs (or an SVG and the page)
 * cannot touch each other.
 *
 * @internal Part of Papercut's engine, not its public API. It may change in any release.
 */
final class InlineSvg
{
    private const string SVG_NS = 'http://www.w3.org/2000/svg';
    private const string XLINK_NS = 'http://www.w3.org/1999/xlink';
    private const string XML_NS = 'http://www.w3.org/XML/1998/namespace';

    /**
     * Why this SVG cannot be written inline, or null when it can. Markup inside <foreignObject> is not SVG
     * and is not written inline; an SVG that cannot be read at all is kept as an image rather than dropped.
     */
    public static function inlinable(string $xml): ?string
    {
        $dom = self::load($xml);
        if ($dom === null || $dom->documentElement === null) {
            return 'the SVG cannot be read';
        }
        foreach ($dom->getElementsByTagName('*') as $el) {
            if (strtolower($el->localName ?? '') === 'foreignobject') {
                return "<foreignObject> can't be written inline";
            }
        }

        return null;
    }

    /**
     * A copy of the document for a page: <metadata>, comments and processing instructions are left out;
     * so are elements outside the SVG namespace with their subtree (a root with no namespace at all is SVG,
     * and so are its unprefixed descendants; prefixed SVG elements are written unprefixed) (sodipodi:namedview, inkscape:*, rdf:*)
     * and attributes in a namespace other than none, xlink and xml (inkscape:label). CDATA becomes text,
     * since an HTML parser reads comments and CDATA by its own rules, and the namespace declarations that
     * only those elements and attributes used go with them.
     */
    private static function cleaned(\DOMDocument $dom): \DOMDocument
    {
        $out = new \DOMDocument();
        $copy = static function (\DOMElement $from, \DOMNode $into) use (&$copy, $out): void {
            $to = $out->createElementNS(self::SVG_NS, $from->localName ?? $from->nodeName);
            $into->appendChild($to);
            foreach ($from->attributes ?? [] as $attr) {
                $ns = $attr->namespaceURI;
                if ($ns === null) {
                    $to->setAttribute($attr->nodeName, $attr->value);
                } elseif ($ns === self::XLINK_NS) {
                    $to->setAttributeNS($ns, 'xlink:' . $attr->localName, $attr->value);
                } elseif ($ns === self::XML_NS) {
                    $to->setAttributeNS($ns, 'xml:' . $attr->localName, $attr->value);
                }
            }
            foreach ($from->childNodes as $child) {
                if ($child instanceof \DOMElement) {
                    // An SVG with no namespace at all (d3's outerHTML) is SVG throughout.
                    $ns = $child->namespaceURI;
                    if (($ns === self::SVG_NS || ($ns === null && $from->namespaceURI === null)) && $child->localName !== 'metadata') {
                        $copy($child, $to);
                    }
                } elseif ($child instanceof \DOMText) { // text and CDATA
                    $to->appendChild($out->createTextNode($child->data));
                }
            }
        };
        if ($dom->documentElement !== null) {
            $copy($dom->documentElement, $out);
        }

        return $out;
    }

    private static function load(string $xml): ?\DOMDocument
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded ? $dom : null;
    }

    private const array IDREF_LISTS = ['aria-labelledby', 'aria-describedby', 'aria-controls', 'aria-owns', 'aria-flowto'];

    /** @var array<string, true> names of the @keyframes in the SVG's own stylesheets, for the one being written */
    private array $keyframes = [];

    /**
     * @param (\Closure(?string, int, bool): string)|null $fontFamily rewrites a font-family list for the page's fonts; null leaves families alone
     */

    public function __construct(private readonly ?\Closure $fontFamily = null)
    {
    }

    /**
     * @param string $xml the SVG (SvgDocument::$xml)
     * @param string $prefix id prefix such as pc-s3-; the root's scope class is the prefix without its trailing dash
     * @param string $alt the accessible name; '' marks the SVG decorative
     * @param array<string, string> $style box declarations for the root
     * @return array{html: string, approximated: list<string>}
     */
    public function write(string $xml, string $prefix, string $alt, array $style): array
    {
        $dom = self::load($xml);
        $root = $dom?->documentElement;
        if ($dom === null || $root === null) {
            return ['html' => '', 'approximated' => []];
        }
        $dom = self::cleaned($dom);
        $root = $dom->documentElement;
        if ($root === null) {
            return ['html' => '', 'approximated' => []];
        }

        $ids = [];
        foreach ($dom->getElementsByTagName('*') as $el) {
            if ($el->hasAttribute('id')) {
                $ids[$el->getAttribute('id')] = true;
            }
        }
        $dropped = [];
        $scope = rtrim($prefix, '-');
        $hasStyle = false;
        $this->keyframes = [];
        foreach ($dom->getElementsByTagName('style') as $el) {
            if (preg_match_all('/@(?:-webkit-)?keyframes\s+([A-Za-z_][\w-]*)/i', $el->textContent, $found) > 0) {
                foreach ($found[1] as $name) {
                    $this->keyframes[$name] = true;
                }
            }
        }
        foreach (iterator_to_array($dom->getElementsByTagName('*')) as $el) {
            $this->rewriteAttributes($el, $ids, $prefix);
            if ($this->keyframes !== [] && $el->hasAttribute('style')) {
                $el->setAttribute('style', $this->rewriteAnimations($el->getAttribute('style'), $prefix));
            }
            if (strtolower($el->localName ?? '') === 'style') {
                $hasStyle = true;
                $css = $this->rewriteStyleFamilies($this->scopeStyles($el->textContent, $scope, $ids, $prefix, $dropped));
                while ($el->firstChild !== null) {
                    $el->removeChild($el->firstChild);
                }
                $el->appendChild($dom->createCDATASection(str_replace(']]>', ']] >', $css)));
            }
        }

        if ($this->fontFamily !== null) {
            $this->rewriteFamilies($root, 400, false);
            // The default for text that names no family: an attribute, so any rule of the SVG's own still wins.
            // Without any <text> there is nothing to set, and asking would record a font the page doesn't use.
            if ($dom->getElementsByTagName('text')->length > 0 && !$root->hasAttribute('font-family') && !self::hasDeclaration($root->getAttribute('style'), 'font-family')) {
                // The root's own weight and style are inherited by the text below it.
                [$rootWeight, $rootItalic] = self::variantOf($root, 400, false);
                $root->setAttribute('font-family', ($this->fontFamily)(null, $rootWeight, $rootItalic));
                // The variants of text that falls back to that default.
                foreach ($this->variantsBelow($root, $rootWeight, $rootItalic) as [$weight, $italic]) {
                    ($this->fontFamily)(null, $weight, $italic);
                }
            }
        }
        if ($hasStyle) {
            $root->setAttribute('class', trim($root->getAttribute('class') . ' ' . $scope));
        }
        // The sizing an <img> gets from the page stylesheet: shrink to the container, keeping the aspect ratio.
        // A box that sets one dimension drops the other attribute, so the view box gives the ratio.
        $root->removeAttribute('content'); // draw.io's copy of the whole diagram
        if (isset($style['width']) && !isset($style['height'])) {
            $root->removeAttribute('height');
        } elseif (isset($style['height']) && !isset($style['width'])) {
            $root->removeAttribute('width');
        }
        $style = array_merge(['display' => 'block', 'max-width' => '100%'], isset($style['height']) ? [] : ['height' => 'auto'], $style);
        $declarations = array_filter([$root->getAttribute('style') !== '' ? rtrim(trim($root->getAttribute('style')), ';') . ';' : '', Css::attr($style)]);
        if ($declarations !== []) {
            $root->setAttribute('style', implode(' ', $declarations));
        }
        if ($alt === '') {
            $root->removeAttribute('role');
            $root->removeAttribute('aria-label');
            $root->setAttribute('aria-hidden', 'true');
        } else {
            $root->removeAttribute('aria-hidden');
            $root->removeAttribute('aria-labelledby');
            $root->setAttribute('role', 'img');
            $root->setAttribute('aria-label', $alt);
        }

        $html = $dom->saveXML($root);

        return [
            'html' => $html === false ? '' : $html,
            'approximated' => array_map(static fn (string $kind): string => 'inline SVG <style> ' . $kind . " removed (can't be scoped)", array_keys($dropped)),
        ];
    }

    /** Rewrites the families set on $el and below; weight and italic are what the parent passes down. */
    private function rewriteFamilies(\DOMElement $el, int $weight, bool $italic): void
    {
        $style = $el->getAttribute('style');
        $weight = self::weightOf($el->getAttribute('font-weight')) ?? $weight;
        $italic = self::italicOf($el->getAttribute('font-style')) ?? $italic;
        $declared = self::declarations($style);
        foreach ($declared as [$name, $value]) {
            if ($name === 'font-weight') {
                $weight = self::weightOf($value) ?? $weight;
            } elseif ($name === 'font-style') {
                $italic = self::italicOf($value) ?? $italic;
            }
        }
        if ($el->hasAttribute('font-family')) {
            $list = $el->getAttribute('font-family');
            $el->setAttribute('font-family', $this->family($list, $weight, $italic));
            $this->recordVariants($list, $el, $weight, $italic);
        }
        if ($style !== '' && (self::hasDeclaration($style, 'font-family') || self::hasDeclaration($style, 'font'))) {
            foreach ($declared as [$name, $value]) {
                if ($name === 'font-family' && !$el->hasAttribute('font-family')) {
                    $this->recordVariants($value, $el, $weight, $italic);
                }
            }
            $el->setAttribute('style', $this->rewriteDeclarations($declared, $weight, $italic));
        }
        foreach ($el->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $this->rewriteFamilies($child, $weight, $italic);
            }
        }
    }

    /** Asks for the family in the variants the text below $el uses, which only records them for @font-face. */
    private function recordVariants(string $list, \DOMElement $el, int $weight, bool $italic): void
    {
        foreach ($this->variantsBelow($el, $weight, $italic) as [$w, $i]) {
            if ($w !== $weight || $i !== $italic) {
                $this->family($list, $w, $i);
            }
        }
    }

    /**
     * The weight and italic of every <text>, <tspan> and <textPath> below $el that takes its family from $el:
     * their own nearest font-weight and font-style, else what is passed down. Elements that set a family of their
     * own are left to their own pass.
     *
     * @return list<array{int, bool}>
     */
    private function variantsBelow(\DOMElement $el, int $weight, bool $italic): array
    {
        $found = [];
        foreach ($el->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }
            if ($child->hasAttribute('font-family') || self::hasDeclaration($child->getAttribute('style'), 'font-family')) {
                continue;
            }
            [$w, $i] = self::variantOf($child, $weight, $italic);
            if (in_array(strtolower($child->localName ?? ''), ['text', 'tspan', 'textpath'], true)) {
                $found[$w . ($i ? 'i' : 'n')] = [$w, $i];
            }
            foreach ($this->variantsBelow($child, $w, $i) as $v) {
                $found[$v[0] . ($v[1] ? 'i' : 'n')] = $v;
            }
        }

        return array_values($found);
    }

    /** @return array{int, bool} */
    private static function variantOf(\DOMElement $el, int $weight, bool $italic): array
    {
        $weight = self::weightOf($el->getAttribute('font-weight')) ?? $weight;
        $italic = self::italicOf($el->getAttribute('font-style')) ?? $italic;
        foreach (self::declarations($el->getAttribute('style')) as [$name, $value]) {
            if ($name === 'font-weight') {
                $weight = self::weightOf($value) ?? $weight;
            } elseif ($name === 'font-style') {
                $italic = self::italicOf($value) ?? $italic;
            }
        }

        return [$weight, $italic];
    }

    /**
     * Replaces the family in every rule of a stylesheet, except @font-face; weight and style are the rule's own.
     * A bold or italic set on an ancestor element is not known here (the rule can match any element), so a rule
     * that sets only a family is recorded as regular.
     */
    private function rewriteStyleFamilies(string $css): string
    {
        if ($this->fontFamily === null || stripos($css, 'font') === false) {
            return $css;
        }

        return preg_replace_callback('/([^{}]*)\{([^{}]*)\}/', function (array $m): string {
            if (str_starts_with(ltrim($m[1]), '@font-face') || stripos($m[2], 'font') === false) {
                return $m[0];
            }
            $weight = 400;
            $italic = false;
            $declared = self::declarations($m[2]);
            foreach ($declared as [$name, $value]) {
                if ($name === 'font-weight') {
                    $weight = self::weightOf($value) ?? $weight;
                } elseif ($name === 'font-style') {
                    $italic = self::italicOf($value) ?? $italic;
                }
            }

            return $m[1] . '{' . $this->rewriteDeclarations($declared, $weight, $italic) . '}';
        }, $css) ?? $css;
    }

    /**
     * Declarations joined again with their font-family and font families rewritten.
     *
     * @param list<array{string, string, string}> $declared
     */
    private function rewriteDeclarations(array $declared, int $weight, bool $italic): string
    {
        $parts = [];
        foreach ($declared as [$name, $value, $raw]) {
            $parts[] = match ($name) {
                'font-family' => $name . ':' . $this->family($value, $weight, $italic),
                'font' => $name . ':' . $this->shorthand($value),
                default => $raw,
            };
        }

        return implode(';', $parts);
    }

    /**
     * A font shorthand with its family list rewritten. The family follows the size (and an optional /line-height);
     * weight and style are what the shorthand names before it, as the shorthand resets the rest. A system font
     * keyword such as menu has no size and is left alone.
     */
    private function shorthand(string $value): string
    {
        $size = '(?:\d*\.?\d+(?:px|pt|em|rem|%|ex|ch|cm|mm|in|pc|vw|vh|vmin|vmax)|(?:xx|x)-(?:small|large)|small|medium|large|smaller|larger)';
        if (preg_match('~^(.*?(?:^|\s))(' . $size . ')(\s*/\s*\S+)?(\s+)(.+)$~is', $value, $m) !== 1) {
            return $value;
        }
        $weight = 400;
        $italic = false;
        foreach (preg_split('/\s+/', trim($m[1])) ?: [] as $token) {
            $weight = self::weightOf($token) ?? $weight;
            $italic = self::italicOf($token) ?? $italic;
        }

        return $m[1] . $m[2] . $m[3] . $m[4] . $this->family($m[5], $weight, $italic);
    }

    /** A family list, with any !important kept, rewritten by the closure. */
    private function family(string $value, int $weight, bool $italic): string
    {
        $important = '';
        if (preg_match('/\s*!\s*important\s*$/i', $value, $m) === 1) {
            $important = ' !important';
            $value = substr($value, 0, -strlen($m[0]));
        }

        return ($this->fontFamily?->__invoke(trim($value), $weight, $italic) ?? trim($value)) . $important;
    }

    /** @return list<array{string, string, string}> property (lowercased), value and the declaration as written */
    private static function declarations(string $css): array
    {
        $out = [];
        $at = 0;
        $n = strlen($css);
        while ($at < $n) {
            $end = self::scanTo($css, $at, ';');
            $raw = substr($css, $at, $end - $at);
            $at = $end + 1;
            if (trim($raw) === '') {
                continue;
            }
            $colon = strpos($raw, ':');
            $out[] = $colon === false
                ? ['', '', $raw]
                : [strtolower(trim(substr($raw, 0, $colon))), trim(substr($raw, $colon + 1)), $raw];
        }

        return $out;
    }

    private static function hasDeclaration(string $style, string $property): bool
    {
        foreach (self::declarations($style) as [$name]) {
            if ($name === $property) {
                return true;
            }
        }

        return false;
    }

    /** 700 for bold, bolder or 600 and up, 400 for normal, lighter or less; null when the value says nothing (inherit, empty). */
    private static function weightOf(string $value): ?int
    {
        $value = strtolower(trim(preg_replace('/\s*!\s*important\s*$/i', '', $value) ?? $value));

        return match (true) {
            $value === 'bold' || $value === 'bolder' => 700,
            $value === 'normal' || $value === 'lighter' => 400,
            is_numeric($value) => (float) $value >= 600 ? 700 : 400,
            default => null,
        };
    }

    private static function italicOf(string $value): ?bool
    {
        $value = strtolower(trim(preg_replace('/\s*!\s*important\s*$/i', '', $value) ?? $value));

        return match (true) {
            str_starts_with($value, 'italic') || str_starts_with($value, 'oblique') => true,
            $value === 'normal' => false,
            default => null,
        };
    }

    /** A declaration list with the keyframe names in animation and animation-name prefixed. */
    private function rewriteAnimations(string $declarations, string $prefix): string
    {
        $names = array_map(static fn (string $n): string => preg_quote($n, '/'), array_keys($this->keyframes));
        $pattern = '/(?<![\w-])(' . implode('|', $names) . ')(?![\w-])/';
        $parts = [];
        foreach (self::declarations($declarations) as [$name, $value, $raw]) {
            if (in_array($name, ['animation', 'animation-name', '-webkit-animation', '-webkit-animation-name'], true) && ($new = preg_replace($pattern, $prefix . '$1', $value)) !== null && $new !== $value) {
                $colon = strpos($raw, ':');
                $parts[] = substr($raw, 0, $colon + 1) . (str_starts_with(substr($raw, $colon + 1), ' ') ? ' ' : '') . $new;
            } else {
                $parts[] = $raw;
            }
        }

        return implode(';', $parts) . (str_ends_with(rtrim($declarations), ';') ? ';' : '');
    }

    /** @param array<string, true> $ids */
    private function rewriteAttributes(\DOMElement $el, array $ids, string $prefix): void
    {
        foreach (iterator_to_array($el->attributes ?? []) as $attr) {
            $name = $attr->nodeName;
            $value = $attr->value;
            if ($name === 'id') {
                $new = $prefix . $value;
            } elseif (($name === 'href' || $name === 'xlink:href') && str_starts_with($value, '#') && isset($ids[substr($value, 1)])) {
                $new = '#' . $prefix . substr($value, 1);
            } elseif (in_array($name, self::IDREF_LISTS, true)) {
                $new = implode(' ', array_map(static fn (string $id): string => isset($ids[$id]) ? $prefix . $id : $id, preg_split('/\s+/', trim($value)) ?: []));
            } elseif ($name === 'begin' || $name === 'end') {
                $new = implode(';', array_map(static function (string $item) use ($ids, $prefix): string {
                    return preg_match('/^(\s*)([A-Za-z_][\w.-]*?)\.(?=\S)/', $item, $m) === 1 && isset($ids[$m[2]])
                        ? $m[1] . $prefix . $m[2] . substr($item, strlen($m[1]) + strlen($m[2]))
                        : $item;
                }, explode(';', $value)));
            } else {
                $new = self::rewriteUrls($value, $ids, $prefix);
            }
            if ($new !== $value) {
                $attr->value = $new;
            }
        }
    }

    /** @param array<string, true> $ids */
    private static function rewriteUrls(string $css, array $ids, string $prefix): string
    {
        if (!str_contains($css, 'url(')) {
            return $css;
        }

        return preg_replace_callback(
            '/url\(\s*(["\']?)#([^)"\'\s]+)\1\s*\)/',
            static fn (array $m): string => isset($ids[$m[2]]) ? 'url(' . $m[1] . '#' . $prefix . $m[2] . $m[1] . ')' : $m[0],
            $css,
        ) ?? $css;
    }

    /**
     * @param array<string, true> $ids
     * @param array<string, true> $dropped kinds of at-rule removed, such as @import
     */
    private function scopeStyles(string $css, string $scope, array $ids, string $prefix, array &$dropped): string
    {
        $css = preg_replace('~/\*.*?\*/~s', '', $css) ?? $css;

        return self::rewriteUrls($this->scopeBlock($css, $scope, $ids, $prefix, $dropped), $ids, $prefix);
    }

    /**
     * @param array<string, true> $ids
     * @param array<string, true> $dropped
     */
    private function scopeBlock(string $css, string $scope, array $ids, string $prefix, array &$dropped): string
    {
        $out = '';
        $n = strlen($css);
        $i = 0;
        while ($i < $n) {
            while ($i < $n && ctype_space($css[$i])) {
                $i++;
            }
            if ($i >= $n) {
                break;
            }
            $start = $i;
            $i = self::scanTo($css, $i, '{;');
            $prelude = trim(substr($css, $start, $i - $start));
            if ($i >= $n || $css[$i] === ';') {
                $i++;
                if (str_starts_with($prelude, '@')) {
                    $dropped[self::atName($prelude)] = true;
                }
                continue;
            }
            $bodyStart = $i + 1;
            $i = self::endOfBlock($css, $bodyStart);
            $body = substr($css, $bodyStart, $i - $bodyStart);
            $i++;
            if (str_starts_with($prelude, '@')) {
                $name = self::atName($prelude);
                if ($name === '@media') {
                    $out .= $prelude . '{' . $this->scopeBlock($body, $scope, $ids, $prefix, $dropped) . '}';
                } elseif ($name === '@font-face') {
                    $out .= $prelude . '{' . $body . '}';
                } elseif (($name === '@keyframes' || $name === '@-webkit-keyframes') && preg_match('/^@\S+\s+([A-Za-z_][\w-]*)\s*$/', $prelude, $km) === 1) {
                    $out .= substr($prelude, 0, strlen($prelude) - strlen($km[1])) . $prefix . $km[1] . '{' . $body . '}';
                } else {
                    $dropped[$name] = true;
                }
                continue;
            }
            $selectors = [];
            foreach (self::splitTop($prelude) as $selector) {
                $selector = trim($selector);
                if ($selector === '') {
                    continue;
                }
                $selector = preg_replace_callback(
                    '/#(-?[_a-zA-Z][\w-]*)/',
                    static fn (array $m): string => isset($ids[$m[1]]) ? '#' . $prefix . $m[1] : $m[0],
                    $selector,
                ) ?? $selector;
                foreach (self::scoped($selector, $scope) as $form) {
                    $selectors[] = $form;
                }
            }
            if ($selectors !== []) {
                $out .= implode(', ', $selectors) . '{' . ($this->keyframes === [] ? $body : $this->rewriteAnimations($body, $prefix)) . '}';
            }
        }

        return $out;
    }

    /**
     * The forms of a selector that match inside the SVG and, when it can name the root, the root itself.
     *
     * @return list<string>
     */
    private static function scoped(string $selector, string $scope): array
    {
        $first = self::scanTo($selector, 0, " \t\r\n>+~");
        $head = substr($selector, 0, $first);
        $rest = substr($selector, $first);
        $forms = [];
        if (preg_match('/^:root(?![\w-])/i', $head) === 1) {
            return ['svg.' . $scope . substr($head, 5) . $rest];
        }
        if (preg_match('/^svg(?![\w-])/i', $head) === 1) {
            $forms[] = 'svg.' . $scope . substr($head, 3) . $rest;
        } elseif ($head !== '' && str_contains('.#[:*', $head[0])) {
            $forms[] = '.' . $scope . ($head[0] === '*' ? substr($head, 1) : $head) . $rest;
        }
        $forms[] = '.' . $scope . ' ' . $selector;

        return $forms;
    }

    private static function atName(string $prelude): string
    {
        return preg_match('/^@[a-zA-Z-]+/', $prelude, $m) === 1 ? strtolower($m[0]) : '@rule';
    }

    /** Index of the first of the characters in $stop at or after $i, outside strings and parentheses; the length if none. */
    private static function scanTo(string $css, int $i, string $stop): int
    {
        $n = strlen($css);
        $depth = 0;
        for (; $i < $n; $i++) {
            $c = $css[$i];
            if ($c === '"' || $c === "'") {
                $i = self::endOfString($css, $i);
            } elseif ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($depth === 0 && str_contains($stop, $c)) {
                return $i;
            }
        }

        return $n;
    }

    /** Index of the brace closing the block whose body starts at $i; the length if it never closes. */
    private static function endOfBlock(string $css, int $i): int
    {
        $n = strlen($css);
        $depth = 1;
        for (; $i < $n; $i++) {
            $c = $css[$i];
            if ($c === '"' || $c === "'") {
                $i = self::endOfString($css, $i);
            } elseif ($c === '{') {
                $depth++;
            } elseif ($c === '}' && --$depth === 0) {
                return $i;
            }
        }

        return $n;
    }

    private static function endOfString(string $css, int $i): int
    {
        $n = strlen($css);
        $quote = $css[$i];
        for ($i++; $i < $n && $css[$i] !== $quote; $i++) {
            if ($css[$i] === '\\') {
                $i++;
            }
        }

        return $i;
    }

    /** @return list<string> */
    private static function splitTop(string $list): array
    {
        $parts = [];
        $depth = 0;
        $quote = '';
        $current = '';
        foreach (str_split($list) as $c) {
            if ($quote !== '') {
                $quote = $c === $quote ? '' : $quote;
            } elseif ($c === '"' || $c === "'") {
                $quote = $c;
            } elseif ($c === '(' || $c === '[') {
                $depth++;
            } elseif ($c === ')' || $c === ']') {
                $depth = max(0, $depth - 1);
            } elseif ($c === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';
                continue;
            }
            $current .= $c;
        }
        $parts[] = $current;

        return $parts;
    }
}
