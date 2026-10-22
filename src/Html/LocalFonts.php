<?php

declare(strict_types=1);

namespace Papercut\Html;

use Papercut\Text\FontFace;

/** Serves the font files registered in the export's FontSet, either embedded as data URIs or linked by URL. */
final readonly class LocalFonts implements FontSource
{
    /**
     * @param list<string>|null $allowed lowercased family allowlist; null allows all
     * @param (\Closure(FontFace): ?string)|null $fontUrl null embeds
     */
    private function __construct(private ?array $allowed, private ?\Closure $fontUrl) {}

    /** @param list<string>|null $families families to serve, case-insensitive; null serves every registered family */
    public static function embed(?array $families = null): self
    {
        return new self(self::lower($families), null);
    }

    /**
     * @param \Closure(FontFace): ?string $fontUrl a URL for the face, or null to leave it out
     * @param list<string>|null $families families to serve, case-insensitive; null serves every registered family
     */
    public static function link(\Closure $fontUrl, ?array $families = null): self
    {
        return new self(self::lower($families), $fontUrl);
    }

    public function __invoke(FontRequest $request): ?FontAnswer
    {
        $family = strtolower($request->family);
        if (!$request->registered || ($this->allowed !== null && !in_array($family, $this->allowed, true))) {
            return null;
        }
        $out = [];
        foreach ($request->fonts->effectiveFaces() as $face) {
            if (strtolower($face->family) !== $family) {
                continue;
            }
            $url = $this->fontUrl === null ? FontFaces::dataUri($face) : ($this->fontUrl)($face);
            if ($url !== null) {
                $out[] = new FontFaceSrc($face->weight, $face->style, $url, 'truetype');
            }
        }

        return $out === [] ? null : FontAnswer::faces(...$out);
    }

    /**
     * @param list<string>|null $families
     * @return list<string>|null
     */
    private static function lower(?array $families): ?array
    {
        return $families === null ? null : array_map('strtolower', $families);
    }
}
