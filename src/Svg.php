<?php

declare(strict_types=1);

namespace Papercut;

use Papercut\Concerns\HasStyle;
use Papercut\Exception\ImageUnreadable;
use Papercut\Vector\SvgDocument;

/**
 * An SVG drawing, kept as vector paths in PDF and embedded as an image in HTML. It is sized
 * like an image: its width and height (or view box) give the natural size, and setting one
 * side keeps the aspect ratio. The SVG's preserveAspectRatio places the drawing in the box.
 */
final class Svg implements Styled
{
    use HasStyle;

    private ?SvgDocument $document;

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
