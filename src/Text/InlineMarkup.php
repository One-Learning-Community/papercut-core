<?php

declare(strict_types=1);

namespace Papercut\Text;

use Papercut\Exception\InvalidValue;
use Papercut\Paint\Link as LinkOp;
use Papercut\Span;
use Papercut\Style\Style;
use Papercut\Value\Color;

/**
 * Parses the small inline tag vocabulary into spans. Tags are recognised
 * first; entities are decoded afterwards, only inside text, so escaped
 * markup (&lt;b&gt;) can never become a tag.
 */
final class InlineMarkup
{
    private const array SPAN_ATTRIBUTES = ['color', 'font', 'font-size', 'weight', 'bold', 'italic', 'underline', 'strike', 'letter-spacing'];

    private const string TAG = '~<(/?)([A-Za-z][A-Za-z0-9]*)((?:\s+[A-Za-z][A-Za-z-]*(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'<>=/]+))?)*)\s*(/?)>~A';

    /** @return list<Span> */
    public static function parse(string $markup): array
    {
        /** @var list<array{tag: string, style: Style, href: ?string, inner: Style, base: ?Style}> $stack */
        $stack = [];
        $style = new Style();
        // Inside <code>: $base is the style set outside it and $inner only what was opened since, so the
        // named code style can sit between them (outer, then code, then inner).
        $base = null;
        $inner = new Style();
        $href = null;
        $spans = [];
        $len = strlen($markup);
        $pos = 0;
        $text = '';

        while ($pos < $len) {
            $lt = strpos($markup, '<', $pos);
            if ($lt === false) {
                $text .= substr($markup, $pos);
                break;
            }
            $text .= substr($markup, $pos, $lt - $pos);
            $next = $markup[$lt + 1] ?? '';
            if ($next === '' || !(ctype_alpha($next) || $next === '/')) {
                $text .= '<';
                $pos = $lt + 1;
                continue;
            }
            if (preg_match(self::TAG, $markup, $m, 0, $lt) !== 1) {
                throw self::notAllowed($markup, substr($markup, $lt, 20));
            }
            $pos = $lt + strlen($m[0]);
            [, $closing, $rawName, $rawAttrs, $selfClose] = $m;
            $name = strtolower($rawName);
            $display = '<' . $closing . $name . '>';

            if ($closing === '/') {
                $top = $stack === [] ? null : $stack[count($stack) - 1];
                if ($top === null || $top['tag'] !== $name) {
                    throw InvalidValue::for('inline markup', $markup)->because('unexpected ' . $display);
                }
                if (trim($rawAttrs) !== '') {
                    throw self::notAllowed($markup, $display);
                }
                self::flush($text, $spans, $base === null ? $style : $inner, $base, $href, self::named($stack));
                array_pop($stack);
                $style = $top['style'];
                $inner = $top['inner'];
                $base = $top['base'];
                $href = $top['href'];
                continue;
            }

            $attrs = self::attributes($rawAttrs);
            if ($name === 'br') {
                if ($attrs !== []) {
                    throw self::notAllowed($markup, $display);
                }
                self::flush($text, $spans, $base === null ? $style : $inner, $base, $href, self::named($stack));
                $spans[] = Span::break();
                continue;
            }
            if ($selfClose === '/') {
                throw self::notAllowed($markup, $display);
            }

            $add = match ($name) {
                'b', 'strong' => self::noAttrs($attrs, $markup, $display, new Style(fontWeight: 700)),
                'i', 'em' => self::noAttrs($attrs, $markup, $display, new Style(italic: true)),
                'u' => self::noAttrs($attrs, $markup, $display, new Style(underline: true)),
                'mark' => self::noAttrs($attrs, $markup, $display, new Style(background: Color::named('yellow-200'))),
                's' => self::noAttrs($attrs, $markup, $display, new Style(strike: true)),
                'a' => self::noAttrs(array_diff_key($attrs, ['href' => 1]), $markup, $display, new Style()),
                'code' => self::noAttrs($attrs, $markup, $display, new Style()),
                'span' => self::spanStyle($attrs, $markup),
                default => throw self::notAllowed($markup, $display),
            };

            self::flush($text, $spans, $base === null ? $style : $inner, $base, $href, self::named($stack));
            $stack[] = ['tag' => $name, 'style' => $style, 'href' => $href, 'inner' => $inner, 'base' => $base];
            if ($name === 'code' && $base === null) {
                $base = $style;
                $inner = new Style();
            }
            $style = $style->merge($add);
            $inner = $inner->merge($add);
            if ($name === 'a') {
                if (!isset($attrs['href'])) {
                    throw InvalidValue::for('inline markup', $markup)->because('<a> needs an href');
                }
                if (!LinkOp::allowed($attrs['href'])) {
                    throw InvalidValue::for('inline markup', $markup)->because('<a href> must use http, https, mailto, tel or a #fragment');
                }
                $href = $attrs['href'];
            }
        }

        if ($stack !== []) {
            throw InvalidValue::for('inline markup', $markup)->because('<' . $stack[count($stack) - 1]['tag'] . '> is not closed');
        }
        self::flush($text, $spans, $base === null ? $style : $inner, $base, $href, self::named($stack));
        return $spans;
    }

    /** @param list<array{tag: string, style: Style, href: ?string, inner: Style, base: ?Style}> $stack */
    private static function named(array $stack): ?string
    {
        foreach ($stack as $open) {
            if ($open['tag'] === 'code') {
                return 'code';
            }
        }
        return null;
    }

    /** @param list<Span> $spans */
    private static function flush(string &$text, array &$spans, Style $style, ?Style $base, ?string $href, ?string $name): void
    {
        if ($text === '') {
            return;
        }
        $decoded = self::decode($text);
        $text = '';
        $last = $spans === [] ? null : $spans[count($spans) - 1];
        if ($last !== null && !$last->lineBreak && $last->href === $href && $last->styleName === $name && $last->style == $style && $last->base == $base) {
            $spans[count($spans) - 1] = new Span($last->text . $decoded, $style, $href, false, $name, $base);
            return;
        }
        $spans[] = new Span($decoded, $style, $href, false, $name, $base);
    }

    /** @return array<string, string> */
    private static function attributes(string $raw): array
    {
        $attrs = [];
        preg_match_all('~([A-Za-z][A-Za-z-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'<>=/]+)))?~', $raw, $all, PREG_SET_ORDER);
        foreach ($all as $a) {
            $value = ($a[2] ?? '') !== '' ? $a[2] : (($a[3] ?? '') !== '' ? $a[3] : ($a[4] ?? ''));
            $attrs[strtolower($a[1])] = $a[0] === $a[1] ? '1' : self::decode($value);
        }
        return $attrs;
    }

    /** @param array<string, string> $attrs */
    private static function noAttrs(array $attrs, string $markup, string $display, Style $style): Style
    {
        if ($attrs !== []) {
            throw self::notAllowed($markup, $display);
        }
        return $style;
    }

    /** @param array<string, string> $attrs */
    private static function spanStyle(array $attrs, string $markup): Style
    {
        foreach (array_keys($attrs) as $key) {
            if (!in_array($key, self::SPAN_ATTRIBUTES, true)) {
                throw self::notAllowed($markup, '<span ' . $key . '>');
            }
        }
        return Style::fromAttributes($attrs);
    }

    private static function decode(string $text): string
    {
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function notAllowed(string $markup, string $tag): InvalidValue
    {
        return InvalidValue::for('inline markup', $markup)->because($tag . ' is not allowed');
    }
}
