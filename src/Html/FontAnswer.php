<?php

declare(strict_types=1);

namespace Papercut\Html;

/** A source's answer: either a stylesheet to import, or font files to declare. */
final readonly class FontAnswer
{
    /** @param list<FontFaceSrc> $faces */
    private function __construct(private ?string $url, private array $faces) {}

    public static function stylesheet(string $url): self
    {
        return new self($url, []);
    }

    public static function faces(FontFaceSrc ...$faces): self
    {
        return new self(null, array_values($faces));
    }

    /** Set for stylesheet answers. */
    public function url(): ?string
    {
        return $this->url;
    }

    /**
     * Set for faces answers. May be empty; FontStack refuses that.
     *
     * @return list<FontFaceSrc>
     */
    public function faceList(): array
    {
        return $this->faces;
    }
}
