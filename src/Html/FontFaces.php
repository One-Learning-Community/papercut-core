<?php

declare(strict_types=1);

namespace Papercut\Html;

use Papercut\Exception\FontNotFound;
use Papercut\Export\FontMode;
use Papercut\Text\{FontFace, FontSet, FontStyle};

/**
 * Builds the @font-face rules for the families a document actually uses.
 *
 * @internal Part of Papercut's engine, not its public API. It may change in any release.
 */
final class FontFaces
{
    /**
     * @param list<string> $families families used by the document
     * @param (\Closure(FontFace): ?string)|null $fontUrl
     * @return string one rule per line, each ending in a newline; empty for FontMode::None
     */
    public static function css(array $families, FontSet $set, FontMode $mode, ?\Closure $fontUrl): string
    {
        if ($mode === FontMode::None) {
            return '';
        }
        $used = array_map('strtolower', $families);
        $out = '';
        foreach ($set->effectiveFaces() as $face) {
            if (!in_array(strtolower($face->family), $used, true)) {
                continue;
            }
            $src = $mode === FontMode::Embed ? self::dataUri($face) : self::link($face, $fontUrl);
            if ($src === null) {
                continue;
            }
            $out .= '@font-face{font-family:' . Css::string($face->family) . ';src:url(' . $src . ') format("truetype");font-weight:' . $face->weight
                . ';font-style:' . ($face->style === FontStyle::Italic ? 'italic' : 'normal') . "}\n";
        }

        return $out;
    }

    /** @throws FontNotFound when the font file cannot be read */
    public static function dataUri(FontFace $face): string
    {
        $bytes = is_file($face->path) && is_readable($face->path) ? file_get_contents($face->path) : false;
        if ($bytes === false) {
            throw new FontNotFound('Font file not found: ' . $face->path);
        }

        return 'data:font/ttf;base64,' . base64_encode($bytes);
    }

    /** @param (\Closure(FontFace): ?string)|null $fontUrl */
    private static function link(FontFace $face, ?\Closure $fontUrl): ?string
    {
        $url = $fontUrl === null ? null : $fontUrl($face);

        return $url === null ? null : Css::string($url);
    }

    /**
     * The @font-face rule for the math font; empty for FontMode::None. Sources embeds it like Embed: no third-party source serves this file.
     *
     * @param (\Closure(FontFace): ?string)|null $fontUrl
     */
    public static function mathCss(FontSet $set, FontMode $mode, ?\Closure $fontUrl): string
    {
        if ($mode === FontMode::None) {
            return '';
        }
        $face = new FontFace(FontSet::MATH_FAMILY, $set->mathFontPath());
        $src = $mode === FontMode::Embed || $mode === FontMode::Sources ? self::dataUri($face) : self::link($face, $fontUrl);

        return $src === null ? '' : '@font-face{font-family:' . Css::string($face->family) . ';src:url(' . $src . ') format("truetype");font-weight:400;font-style:normal}' . "\n";
    }
}
