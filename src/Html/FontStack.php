<?php

declare(strict_types=1);

namespace Papercut\Html;

use Papercut\Export\Fidelity;
use Papercut\Text\FontStyle;

/** The result of asking an ordered stack of font sources about every family a document uses. */
final readonly class FontStack
{
    /** @param list<string> $links */
    private function __construct(private array $links, private string $faceCss) {}

    /**
     * Asks each source in order for each family; the first answer with safe URLs wins.
     *
     * @param list<array{request: FontRequest, path: string}> $requests
     * @param list<FontSource|\Closure(FontRequest): ?FontAnswer> $sources
     */
    public static function resolve(array $requests, array $sources, Fidelity $fidelity): self
    {
        $links = [];
        $css = '';
        foreach ($requests as ['request' => $request, 'path' => $path]) {
            $accepted = false;
            foreach ($sources as $i => $source) {
                $answer = $source($request);
                if ($answer === null) {
                    continue;
                }
                if (!self::safe($answer)) {
                    $fidelity->warn('font source ' . $i . ' returned an unsafe URL for "' . $request->family . '"; trying the next source');
                    continue;
                }
                $url = $answer->url();
                if ($url !== null) {
                    $links[$url] = $url;
                } else {
                    foreach ($answer->faceList() as $face) {
                        $css .= self::rule($request->family, $face);
                    }
                }
                $accepted = true;
                break;
            }
            if (!$accepted) {
                $fidelity->approximated('font not linked', $path);
            }
        }

        return new self(array_values($links), $css);
    }

    /** @return list<string> stylesheet URLs, deduplicated, in family order */
    public function links(): array
    {
        return $this->links;
    }

    /** @font-face rules, one per line. */
    public function faceCss(): string
    {
        return $this->faceCss;
    }

    /** One @import per link, one per line; it must come first in a stylesheet. */
    public function importCss(): string
    {
        $out = '';
        foreach ($this->links as $link) {
            $out .= '@import url(' . Css::string($link) . ");\n";
        }

        return $out;
    }

    private static function safe(FontAnswer $answer): bool
    {
        $url = $answer->url();
        if ($url !== null) {
            return Urls::safeStylesheet($url);
        }
        $faces = $answer->faceList();
        if ($faces === []) {
            return false;
        }
        foreach ($faces as $face) {
            if (!Urls::safeFontSrc($face->url)) {
                return false;
            }
        }

        return true;
    }

    private static function rule(string $family, FontFaceSrc $face): string
    {
        // Embedded fonts keep the bare data URI FontFaces writes; anything else is quoted.
        $src = preg_match('~^data:font/[a-z0-9.+-]+;base64,[A-Za-z0-9+/=]*$~i', $face->url) === 1 ? $face->url : Css::string($face->url);

        return '@font-face{font-family:' . Css::string($family) . ';src:url(' . $src . ') format(' . Css::string($face->format) . ');font-weight:' . $face->weight
            . ';font-style:' . ($face->style === FontStyle::Italic ? 'italic' : 'normal') . "}\n";
    }
}
