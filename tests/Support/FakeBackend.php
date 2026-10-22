<?php

declare(strict_types=1);

namespace Papercut\Tests\Support;

use Papercut\Backend\{Backend, FontHandle, ImageHandle};
use Papercut\Paint\{DocumentMeta, PageDisplayList};

final class FakeBackend implements Backend
{
    /** @var array<string, FakeFont> */
    private array $fonts = [];

    /** @param list<string> $coverAll basenames of font files whose fake font covers every glyph */
    public function __construct(private readonly bool $kerning = false, private readonly array $coverAll = [])
    {
    }

    public function loadFont(string $path): FontHandle
    {
        return $this->fonts[$path] ??= new FakeFont('fake:' . basename($path), $this->kerning, in_array(basename($path), $this->coverAll, true));
    }

    public function loadImageFile(string $path): ImageHandle
    {
        return $this->image(sha1($path));
    }

    public function loadImageBytes(string $bytes): ImageHandle
    {
        return $this->image(sha1($bytes));
    }

    public function render(DocumentMeta $meta, array $pages): string
    {
        return serialize(array_map(static fn (PageDisplayList $p): array => [$p->width, $p->height, count($p->ops)], $pages));
    }

    private function image(string $id): ImageHandle
    {
        return new class ($id) implements ImageHandle {
            public function __construct(private readonly string $id)
            {
            }

            public function id(): string
            {
                return $this->id;
            }

            public function widthPx(): int
            {
                return 100;
            }

            public function heightPx(): int
            {
                return 50;
            }
        };
    }
}
