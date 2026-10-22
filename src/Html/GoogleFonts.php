<?php

declare(strict_types=1);

namespace Papercut\Html;

use Papercut\Text\FontStyle;

/**
 * Links a family from Google Fonts (or a compatible host such as Bunny Fonts).
 *
 * Privacy: the visitor's browser fetches the stylesheet and fonts, so it sends its
 * IP address to the host. LG München I (3 O 17493/20, January 2022) held that this
 * can breach data protection law without consent. Bunny Fonts is a host change:
 * pass its host and keep the rest.
 */
final readonly class GoogleFonts implements FontSource
{
    public const array DISPLAYS = ['auto', 'block', 'swap', 'fallback', 'optional'];

    private string $host;

    /** @var list<string>|null lowercased */
    private ?array $families;

    /**
     * @param list<string>|null $families case-insensitive allowlist; null accepts every family
     * @throws \InvalidArgumentException for a bad host or display
     */
    public function __construct(string $host = 'https://fonts.googleapis.com', private string $display = 'swap', ?array $families = null)
    {
        if (!self::validHost($host)) {
            throw new \InvalidArgumentException("GoogleFonts host must be an http or https URL with no query or fragment: '$host'");
        }
        if (!in_array($display, self::DISPLAYS, true)) {
            throw new \InvalidArgumentException("GoogleFonts display must be one of auto, block, swap, fallback, optional: '$display'");
        }
        $this->host = rtrim($host, '/');
        $this->families = $families === null ? null : array_map('strtolower', $families);
    }

    /** True for a clean http or https URL that has a host and no query, fragment, whitespace, quote, angle bracket or backslash. */
    public static function validHost(string $host): bool
    {
        if (!Urls::clean($host) || preg_match('/[\s"\'<>\\\\]/', $host) === 1 || str_contains($host, '?') || str_contains($host, '#')) {
            return false;
        }
        $parts = parse_url($host);

        return $parts !== false
            && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && ($parts['host'] ?? '') !== '';
    }

    public function __invoke(FontRequest $request): ?FontAnswer
    {
        if ($this->families !== null && !in_array(strtolower($request->family), $this->families, true)) {
            return null;
        }

        return FontAnswer::stylesheet(
            $this->host . '/css2?family=' . str_replace('%20', '+', rawurlencode($request->family))
            . $this->axes($request->faces) . '&display=' . $this->display,
        );
    }

    /** @param non-empty-list<FontVariant> $faces sorted by FontRequest */
    private function axes(array $faces): string
    {
        $italic = false;
        foreach ($faces as $face) {
            $italic = $italic || $face->style === FontStyle::Italic;
        }
        if ($italic) {
            // Google answers HTTP 400 unless tuples are sorted by ital, then weight.
            return ':ital,wght@' . implode(';', array_map(
                static fn (FontVariant $f): string => ($f->style === FontStyle::Italic ? 1 : 0) . ',' . $f->weight,
                $faces,
            ));
        }
        if (count($faces) === 1 && $faces[0]->weight === 400) {
            return '';
        }

        return ':wght@' . implode(';', array_map(static fn (FontVariant $f): int => $f->weight, $faces));
    }
}
