<?php

declare(strict_types=1);

namespace Papercut;

use Papercut\Concerns\HasStyle;
use Papercut\Style\ImageFit;

final class Image implements Styled
{
    use HasStyle;

    private ImageFit $fit = ImageFit::Contain;

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

    public function fit(ImageFit $fit): self
    {
        $clone = clone $this;
        $clone->fit = $fit;
        return $clone;
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

    public function debugName(): string
    {
        return 'image';
    }
}
