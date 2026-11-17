<?php

declare(strict_types=1);

namespace Quoin\Backend;

use Quoin\Paint\DocumentMeta;
use Quoin\Paint\PageDisplayList;

/** @internal Part of the PDF writer. Custom backends aren't supported, and it may change in any release. */
interface Backend
{
    /** @throws \Quoin\Exception\FontNotFound */
    public function loadFont(string $path): FontHandle;

    /** @throws \Quoin\Exception\ImageUnreadable */
    public function loadImageFile(string $path): ImageHandle;

    /** @throws \Quoin\Exception\ImageUnreadable */
    public function loadImageBytes(string $bytes): ImageHandle;

    /**
     * @param list<PageDisplayList> $pages
     * @throws \InvalidArgumentException when $pages is empty
     * @throws \LogicException for a font/image handle from another backend or an unknown op type
     */
    public function render(DocumentMeta $meta, array $pages): string;
}
