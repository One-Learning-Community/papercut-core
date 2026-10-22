<?php

declare(strict_types=1);

namespace Papercut\Backend;

use Papercut\Paint\DocumentMeta;
use Papercut\Paint\PageDisplayList;

interface Backend
{
    /** @throws \Papercut\Exception\FontNotFound */
    public function loadFont(string $path): FontHandle;

    /** @throws \Papercut\Exception\ImageUnreadable */
    public function loadImageFile(string $path): ImageHandle;

    /** @throws \Papercut\Exception\ImageUnreadable */
    public function loadImageBytes(string $bytes): ImageHandle;

    /**
     * @param list<PageDisplayList> $pages
     * @throws \InvalidArgumentException when $pages is empty
     * @throws \LogicException for a font/image handle from another backend or an unknown op type
     */
    public function render(DocumentMeta $meta, array $pages): string;
}
