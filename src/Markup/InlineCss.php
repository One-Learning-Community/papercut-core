<?php

declare(strict_types=1);

namespace Papercut\Markup;

use Papercut\Exception\InvalidValue;
use Papercut\Style\ListMarker;
use Papercut\Style\Style;
use Papercut\Style\TextAlign;
use Papercut\Value\Color;
use Papercut\Value\Edges;
use Papercut\Value\Length;
use Papercut\Value\Percent;

/**
 * Reads an HTML `style` attribute into a Papercut Style. Only the properties
 * below are understood, and their values go through Papercut's own parsers (so
 * `px` converts at 0.75pt); every other declaration, and every value those
 * parsers reject, is returned in `dropped` as written. Span-level elements
 * ($inline) take the text properties only; `list-style-type` applies only
 * where the caller says a list takes it ($list).
 *
 * @internal
 */
final class InlineCss
{
    /** Properties that apply to span-level elements. */
    private const array TEXT = ['color', 'font-size', 'font-weight', 'font-style', 'text-decoration', 'background-color'];

    private const array BOX = [
        'width' => 'width', 'height' => 'height',
        'min-width' => 'minWidth', 'max-width' => 'maxWidth',
        'min-height' => 'minHeight', 'max-height' => 'maxHeight',
    ];

    /**
     * @return array{style: Style, dropped: list<string>, float: bool, marker: ?ListMarker}
     */
    public static function parse(string $declarations, bool $inline, bool $list = false, bool $rule = false): array
    {
        $props = [];
        $dropped = [];
        $float = false;
        $marker = null;
        foreach (explode(';', $declarations) as $declaration) {
            $declaration = trim($declaration);
            if ($declaration === '') {
                continue;
            }
            $colon = strpos($declaration, ':');
            $property = $colon === false ? '' : strtolower(trim(substr($declaration, 0, $colon)));
            // a trailing `!important` changes nothing for a single element's own style
            $value = $colon === false ? '' : substr($declaration, $colon + 1);
            $value = trim(preg_replace('/!\s*important\s*$/i', '', $value) ?? $value);
            if ($inline && !in_array($property, self::TEXT, true)) {
                if ($property !== 'float' || strtolower($value) !== 'none') {
                    $dropped[] = $declaration;
                }
                continue;
            }
            if ($rule && in_array($property, ['color', 'border-color', 'border-top-color', 'border-bottom-color'], true)) {
                try {
                    $props['borderColor'] = Color::parse($value);
                } catch (InvalidValue) {
                    $dropped[] = $declaration;
                }
                continue;
            }
            try {
                $set = self::property($property, $value, $list);
            } catch (InvalidValue) {
                $set = null;
            }
            if ($set === null) {
                $dropped[] = $declaration;
            } elseif (array_key_exists('float', $set)) {
                $float = $set['float'] === true;
            } elseif (array_key_exists('marker', $set)) {
                $marker = $set['marker'];
            } else {
                $props = [...$props, ...$set];
            }
        }

        /** @var array<string, mixed> $props */
        return ['style' => new Style(...$props), 'dropped' => $dropped, 'float' => $float, 'marker' => $marker];
    }

    /**
     * The Style properties one declaration sets (or `float`/`marker`), or null when it is not understood.
     *
     * @return array<string, mixed>|null
     */
    private static function property(string $property, string $value, bool $list): ?array
    {
        $keyword = strtolower($value);
        if (isset(self::BOX[$property])) {
            return [self::BOX[$property] => self::lengthOrPercent(self::units($value))];
        }
        return match ($property) {
            'color' => ['color' => Color::parse($value)],
            'background-color' => ['background' => Color::parse($value)],
            'font-size' => self::positive(self::units($value)),
            'font-weight' => self::weight($keyword),
            'font-style' => match ($keyword) {
                'italic' => ['italic' => true],
                'normal' => ['italic' => false],
                default => null,
            },
            'text-decoration' => match ($keyword) {
                'underline' => ['underline' => true],
                'line-through' => ['strike' => true],
                'none' => ['underline' => false, 'strike' => false],
                default => null,
            },
            'text-align' => ($align = TextAlign::tryFrom($keyword)) === null ? null : ['textAlign' => $align],
            'margin' => ['margin' => Edges::parse(self::units($value))],
            'padding' => self::padding(self::units($value)),
            'list-style-type' => $list && ($marker = ListMarker::tryFrom($keyword)) !== null ? ['marker' => $marker] : null,
            'float' => match ($keyword) {
                'left', 'right' => ['float' => true],
                'none' => ['float' => false],
                default => null,
            },
            default => null,
        };
    }

    /** Lowercases the unit after each number (`12PX` → `12px`); CSS units are case-insensitive. */
    private static function units(string $value): string
    {
        return preg_replace_callback('/(\d)([a-z]+)/i', static fn (array $m): string => $m[1] . strtolower($m[2]), $value) ?? $value;
    }

    private static function lengthOrPercent(string $value): Length|Percent
    {
        $v = str_ends_with($value, '%') ? Percent::parse($value) : Length::parse($value);
        if (($v instanceof Length ? $v->points : $v->value) < 0) {
            throw InvalidValue::for('length', $value);
        }
        return $v;
    }

    /** @return array{fontSize: Length}|null */
    private static function positive(string $value): ?array
    {
        $length = Length::parse($value);
        return $length->points > 0 ? ['fontSize' => $length] : null;
    }

    /** @return array{fontWeight: int}|null */
    private static function weight(string $keyword): ?array
    {
        $weight = match ($keyword) {
            'bold' => 700,
            'normal' => 400,
            default => preg_match('/^[1-9]00$/', $keyword) === 1 ? (int) $keyword : null,
        };
        return $weight === null ? null : ['fontWeight' => $weight];
    }

    /** @return array{padding: Edges}|null */
    private static function padding(string $value): ?array
    {
        $edges = Edges::parse($value);
        foreach ([$edges->top, $edges->right, $edges->bottom, $edges->left] as $side) {
            if ($side->points < 0) {
                return null;
            }
        }
        return ['padding' => $edges];
    }
}
