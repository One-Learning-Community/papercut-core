<?php

declare(strict_types=1);

namespace Papercut\Text;

use Papercut\Math\Math;

/** Format-neutral font configuration: extra faces on top of the bundled Inter and Cousine, and glyph fallback families. */
final readonly class FontSet
{
    /** The family the math face is registered under, for PDF text runs. */
    public const string MATH_FAMILY = 'Papercut Math';

    /** @var array<string, string> the name Word should use for a family, by lowercased family */
    private array $wordNames;

    /**
     * @param list<FontFace> $faces
     * @param list<string> $fallbacks families tried, in order, for glyphs the primary font lacks
     * @param array<string, string> $wordNames the name Word should use for a family, keyed by family (any case)
     * @param ?string $mathFont path of a TrueType math font; null means the bundled STIX Two Math
     */
    public function __construct(
        public array $faces = [],
        public array $fallbacks = [],
        array $wordNames = [],
        private ?string $mathFont = null,
    ) {
        $this->wordNames = array_change_key_case($wordNames, CASE_LOWER);
    }

    /** @return list<FontFace> the bundled Inter then Cousine faces, in registration order */
    public static function bundled(): array
    {
        $dir = __DIR__ . '/../../resources/fonts/Inter';
        $mono = __DIR__ . '/../../resources/fonts/Cousine';
        return [
            new FontFace('Inter', "$dir/Inter-Regular.ttf", 400),
            new FontFace('Inter', "$dir/Inter-Italic.ttf", 400, FontStyle::Italic),
            new FontFace('Inter', "$dir/Inter-Bold.ttf", 700),
            new FontFace('Inter', "$dir/Inter-BoldItalic.ttf", 700, FontStyle::Italic),
            new FontFace('Cousine', "$mono/Cousine-Regular.ttf", 400),
            new FontFace('Cousine', "$mono/Cousine-Italic.ttf", 400, FontStyle::Italic),
            new FontFace('Cousine', "$mono/Cousine-Bold.ttf", 700),
            new FontFace('Cousine', "$mono/Cousine-BoldItalic.ttf", 700, FontStyle::Italic),
        ];
    }

    /**
     * Bundled then custom faces, one per family (case-insensitive), weight and style: the last
     * registration wins, and survivors keep the order their key was first registered in.
     *
     * @return list<FontFace>
     */
    public function effectiveFaces(): array
    {
        $byKey = [];
        foreach ([...self::bundled(), ...$this->faces] as $face) {
            $byKey[strtolower($face->family) . '|' . $face->weight . '|' . $face->style->value] = $face;
        }

        return array_values($byKey);
    }

    public function with(FontFace ...$faces): self
    {
        return new self([...$this->faces, ...array_values($faces)], $this->fallbacks, $this->wordNames, $this->mathFont);
    }

    public function withFallbacks(string ...$families): self
    {
        return new self($this->faces, [...$this->fallbacks, ...array_values($families)], $this->wordNames, $this->mathFont);
    }

    /** The font equations are set in (PDF and HTML; Word always uses Cambria Math). It must be a TrueType math font. */
    public function withMathFont(string $path): self
    {
        // The bundled font by another spelling of its path is still the bundled font.
        $bundled = realpath(Math::stixTwoMathPath());
        $custom = $bundled !== false && realpath($path) === $bundled ? null : $path;

        return new self($this->faces, $this->fallbacks, $this->wordNames, $custom);
    }

    public function mathFontPath(): string
    {
        return $this->mathFont ?? Math::stixTwoMathPath();
    }

    /**
     * True when a font other than the bundled one was chosen.
     *
     * @internal Part of Papercut's engine, not its public API. It may change in any release.
     */
    public function hasCustomMathFont(): bool
    {
        return $this->mathFont !== null;
    }

    /** The name Word should use for a family (case-insensitive match; the last call for a family wins). */
    public function withWordName(string $family, string $wordName): self
    {
        $names = $this->wordNames;
        $names[strtolower($family)] = $wordName;

        return new self($this->faces, $this->fallbacks, $names, $this->mathFont);
    }

    /** The Word name set for the family, else Courier New for the bundled Cousine, else the family itself. */
    public function wordName(string $family): string
    {
        $key = strtolower($family);

        return $this->wordNames[$key] ?? ($key === 'cousine' ? 'Courier New' : $family);
    }

    /** What the family's regular face (400, normal; else its first face) says about its kind, or null for an unknown family. */
    public function info(string $family): ?FontInfo
    {
        $key = strtolower($family);
        $chosen = null;
        foreach ($this->effectiveFaces() as $face) {
            if (strtolower($face->family) !== $key) {
                continue;
            }
            $chosen ??= $face;
            if ($face->weight === 400 && $face->style === FontStyle::Normal) {
                $chosen = $face;
                break;
            }
        }

        return $chosen === null ? null : FontInfo::read($chosen->path);
    }
}
