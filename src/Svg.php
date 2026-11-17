<?php

declare(strict_types=1);

namespace Quoin;

use Quoin\Concerns\HasStyle;
use Quoin\Exception\ImageUnreadable;
use Quoin\Support\AltText;
use Quoin\Vector\SvgDocument;

/**
 * An SVG drawing, kept as vector paths in PDF and embedded as an image in HTML. It is sized
 * like an image: its width and height (or view box) give the natural size, and setting one
 * side keeps the aspect ratio. The SVG's preserveAspectRatio places the drawing in the box.
 */
final class Svg implements Styled
{
    use HasStyle;

    private ?SvgDocument $document;

    private ?string $alt = null;

    private function __construct(
        private readonly string $kind,
        private readonly string $value,
    ) {
        $this->document = $kind === 'string' ? SvgDocument::parse($value) : null;
    }

    /** The file is read when the document is laid out or exported. */
    public static function file(string $path): self
    {
        return new self('file', $path);
    }

    /** @throws ImageUnreadable when the markup is not SVG, is unsafe, or is too large to draw */
    public static function fromString(string $svg): self
    {
        return new self('string', $svg);
    }

    /**
     * Describes the drawing for people who cannot see it. HTML export writes it as the alt text;
     * runs of whitespace are collapsed to one space. An empty string marks the drawing as
     * decorative. Without a call to this, the SVG's own title is used.
     */
    public function alt(string $text): self
    {
        $clone = clone $this;
        $clone->alt = AltText::normalize($text);
        return $clone;
    }

    /**
     * The alt text: the one set with alt(), else the SVG's title. An empty string means decorative.
     *
     * @throws ImageUnreadable when the SVG has no alt() and its markup cannot be read for a title
     */
    public function altText(): string
    {
        return $this->alt ?? $this->document()->title;
    }

    /** @return array{kind: 'file'|'string', value: string} */
    public function source(): array
    {
        /** @var 'file'|'string' $kind */
        $kind = $this->kind;

        return ['kind' => $kind, 'value' => $this->value];
    }

    /** @throws ImageUnreadable */
    public function document(): SvgDocument
    {
        if ($this->document !== null) {
            return $this->document;
        }
        $bytes = is_file($this->value) && is_readable($this->value) ? file_get_contents($this->value) : false;
        if ($bytes === false) {
            throw new ImageUnreadable(sprintf('Cannot read SVG "%s": file not found or not readable', $this->value));
        }
        try {
            return $this->document = SvgDocument::parse($bytes);
        } catch (ImageUnreadable $e) {
            throw new ImageUnreadable(sprintf('Cannot read SVG "%s": %s', $this->value, substr($e->getMessage(), strlen('Cannot read SVG: '))), 0, $e);
        }
    }

    public function debugName(): string
    {
        return 'svg';
    }
}
