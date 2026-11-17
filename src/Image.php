<?php

declare(strict_types=1);

namespace Quoin;

use Quoin\Concerns\HasStyle;
use Quoin\Style\ImageFit;
use Quoin\Support\{AltText, EnumValue};

final class Image implements Styled
{
    use HasStyle;

    private ImageFit $fit = ImageFit::Contain;

    private string $alt = '';

    private function __construct(
        private readonly string $kind,
        private readonly string $value,
    ) {
    }

    public static function file(string $path): self
    {
        return new self('file', $path);
    }

    public static function bytes(string $bytes): self
    {
        return new self('bytes', $bytes);
    }

    /** @throws \Quoin\Exception\InvalidValue for a string that is not a fit mode */
    public function fit(ImageFit|string $fit): self
    {
        $fit = EnumValue::from(ImageFit::class, $fit, 'Image::fit()');
        $clone = clone $this;
        $clone->fit = $fit;
        return $clone;
    }

    /**
     * Describes the picture for people who cannot see it. HTML export writes it as the alt text;
     * runs of whitespace are collapsed to one space. An image with no alt text is decorative.
     */
    public function alt(string $text): self
    {
        $clone = clone $this;
        $clone->alt = AltText::normalize($text);
        return $clone;
    }

    /** The alt text set with alt(), or an empty string. */
    public function altText(): string
    {
        return $this->alt;
    }

    public function fitMode(): ImageFit
    {
        return $this->fit;
    }

    /**
     * @return array{kind: 'file'|'bytes', value: string}
     */
    public function source(): array
    {
        /** @var 'file'|'bytes' $kind */
        $kind = $this->kind;
        return ['kind' => $kind, 'value' => $this->value];
    }

    protected function sizesNeedArea(): bool
    {
        return true;
    }

    public function debugName(): string
    {
        return 'image';
    }
}
