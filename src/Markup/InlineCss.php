<?php

declare(strict_types=1);

namespace Papercut\Markup;

use Papercut\Exception\InvalidValue;
use Papercut\Export\FidelityLevel;
use Papercut\Style\ListMarker;
use Papercut\Style\Style;
use Papercut\Style\TextAlign;
use Papercut\Value\Color;
use Papercut\Value\ColumnSpec;
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

    private const array COLUMN = ['column-count', 'columns', 'column-gap', 'column-rule'];

    private const array BOX = [
        'width' => 'width', 'height' => 'height',
        'min-width' => 'minWidth', 'max-width' => 'maxWidth',
        'min-height' => 'minHeight', 'max-height' => 'maxHeight',
    ];

    /**
     * `columns` is null unless a column declaration was read; `columnDeclarations` are those declarations as
     * written; `notes` are features kept in part (a `columns` width, a non-solid rule style).
     *
     * @return array{style: Style, dropped: list<string>, float: bool, marker: ?ListMarker, columns: ?array{count: ?int, gap: ?Length, rule: ?array{width: Length, color: Color}}, columnDeclarations: list<string>, notes: list<array{FidelityLevel, string}>}
     */
    public static function parse(string $declarations, bool $inline, bool $list = false, bool $rule = false): array
    {
        $props = [];
        $dropped = [];
        $float = false;
        $marker = null;
        /** @var array{count: ?int, gap: ?Length, rule: ?array{width: Length, color: Color}}|null $columns */
        $columns = null;
        $columnDeclarations = [];
        $notes = [];
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
            if (in_array($property, self::COLUMN, true)) {
                $columnDeclarations[] = $declaration;
                try {
                    $columns ??= ['count' => null, 'gap' => null, 'rule' => null];
                    /** @var array{count: ?int, gap: ?Length, rule: ?array{width: Length, color: Color}} $columns */
                    $columns = [...$columns, ...self::column($property, $value, $declaration, $notes)];
                } catch (InvalidValue) {
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
        return ['style' => new Style(...$props), 'dropped' => $dropped, 'float' => $float, 'marker' => $marker, 'columns' => $columns, 'columnDeclarations' => $columnDeclarations, 'notes' => $notes];
    }

    /**
     * The column settings one declaration changes.
     *
     * @param list<array{FidelityLevel, string}> $notes
     * @return array<string, mixed>
     * @throws InvalidValue
     */
    private static function column(string $property, string $value, string $declaration, array &$notes): array
    {
        $keyword = strtolower($value);
        switch ($property) {
            case 'column-count':
                return ['count' => self::count($keyword, $declaration, $notes)];
            case 'columns':
                $count = null;
                foreach (preg_split('/\s+/', $keyword) ?: [] as $part) {
                    if ($part === 'auto') {
                        continue;
                    }
                    if (preg_match('/^\d+$/', $part) === 1) {
                        $count = $count === null ? self::count($part, $declaration, $notes) : throw InvalidValue::for('columns', $value);
                        continue;
                    }
                    Length::parse(self::units($part))->absolute('column width');
                    $notes[] = [FidelityLevel::Dropped, 'markup CSS "' . $declaration . '" (column width part)'];
                }
                if ($count === null) {
                    throw InvalidValue::for('columns', $value);
                }
                return ['count' => $count];
            case 'column-gap':
                if ($keyword === 'normal') {
                    return ['gap' => null];
                }
                $gap = Length::parse(self::units($value))->absolute('column gap');
                if ($gap->points < 0) {
                    throw InvalidValue::for('column gap', $value);
                }
                return ['gap' => $gap];
        }
        return ['rule' => self::rule($keyword, $value, $declaration, $notes)];
    }

    /**
     * @param list<array{FidelityLevel, string}> $notes
     * @throws InvalidValue
     */
    private static function count(string $value, string $declaration, array &$notes): int
    {
        if (preg_match('/^\d+$/', $value) !== 1) {
            throw InvalidValue::for('column count', $value);
        }
        $digits = ltrim($value, '0');
        if ($digits === '') {
            throw InvalidValue::for('column count', $value);
        }
        // compare as text first, so a value too big for an int never gets cast
        if (strlen($digits) > 3 || (int) $digits > ColumnSpec::MAX_COLUMNS) {
            $notes[] = [FidelityLevel::Approximated, 'markup CSS "' . $declaration . '" (column count limited to ' . ColumnSpec::MAX_COLUMNS . ')'];
            return ColumnSpec::MAX_COLUMNS;
        }
        return (int) $digits;
    }

    /**
     * @param list<array{FidelityLevel, string}> $notes
     * @return array{width: Length, color: Color}|null null for no rule
     * @throws InvalidValue
     */
    private static function rule(string $keyword, string $value, string $declaration, array &$notes): ?array
    {
        $width = null;
        $color = null;
        $style = null;
        preg_match_all('/[^\s(]+(?:\([^)]*\))?/', $value, $parts);
        foreach ($parts[0] as $part) {
            $lower = strtolower($part);
            if (in_array($lower, ['none', 'hidden', 'solid', 'dashed', 'dotted', 'double', 'groove', 'ridge', 'inset', 'outset'], true)) {
                $style = $style === null ? $lower : throw InvalidValue::for('column rule', $value);
                continue;
            }
            $thick = ['thin' => '1px', 'medium' => '3px', 'thick' => '5px'][$lower] ?? null;
            if ($thick !== null || preg_match('/^[+\-.\d]/', $lower) === 1) {
                $length = Length::parse(self::units($thick ?? $lower))->absolute('column rule width');
                if ($length->points < 0) {
                    throw InvalidValue::for('column rule width', $part);
                }
                $width = $width === null ? $length : throw InvalidValue::for('column rule', $value);
                continue;
            }
            $color = $color === null ? ($lower === 'currentcolor' ? Color::black() : Color::parse($part)) : throw InvalidValue::for('column rule', $value);
        }
        if ($style === null) {
            throw InvalidValue::for('column rule', $value)->because('give a line style');
        }
        if ($style === 'none' || $style === 'hidden' || ($width !== null && $width->points <= 0)) {
            return null;
        }
        if ($style !== 'solid') {
            $notes[] = [FidelityLevel::Approximated, 'markup CSS "' . $declaration . '" (' . $style . ' rule drawn solid)'];
        }
        return ['width' => $width ?? Length::parse('3px'), 'color' => $color ?? Color::black()];
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
