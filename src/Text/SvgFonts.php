<?php

declare(strict_types=1);

namespace Papercut\Text;

/**
 * Picks the font for each SVG <text> from the registered families, so SVG text is embedded
 * like any other text. A font-family the document registered wins; otherwise the SVG element's
 * own family is used. Text that font cannot show tries the fallback families.
 */
final class SvgFonts
{
    /** @var array<string, true> */
    private array $warned = [];

    /**
     * @param list<string> $fallbacks
     * @param (\Closure(string): void)|null $onWarning
     */
    public function __construct(
        private readonly FontRegistry $fonts,
        private readonly string $family,
        private readonly array $fallbacks = [],
        private readonly ?\Closure $onWarning = null,
    ) {
    }

    /** @param string|null $family the SVG's font-family list, like "'Open Sans', Arial, sans-serif" */
    public function resolve(?string $family, bool $bold, bool $italic, string $text): CachedFont
    {
        $weight = $bold ? 700 : 400;
        $style = $italic ? FontStyle::Italic : FontStyle::Normal;
        $primary = $this->fonts->resolve($this->registered($family) ?? $this->family, $weight, $style);
        if ($primary->covers($text)) {
            return $primary;
        }
        foreach ($this->fallbacks as $fallback) {
            if ($this->fonts->has($fallback)) {
                $candidate = $this->fonts->resolve($fallback, $weight, $style);
                if ($candidate->covers($text)) {
                    return $candidate;
                }
            }
        }
        if ($this->onWarning !== null && !isset($this->warned[$text])) {
            $this->warned[$text] = true;
            ($this->onWarning)(sprintf('Missing glyphs for "%s" in SVG text', $text));
        }

        return $primary;
    }

    private function registered(?string $list): ?string
    {
        foreach (explode(',', (string) $list) as $name) {
            $name = trim($name, " \t\n\r\"'");
            if ($name !== '' && $this->fonts->has($name)) {
                return $name;
            }
        }

        return null;
    }
}
