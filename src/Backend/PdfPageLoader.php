<?php

declare(strict_types=1);

namespace Papercut\Backend;

/** A backend that can draw pages of existing PDFs. Backends without it cannot draw a PdfPage. */
interface PdfPageLoader
{
    /**
     * @param int $page counting from 1
     * @throws \Papercut\Exception\PdfUnreadable when the bytes are not a readable PDF, the password is wrong, or the page is missing
     */
    public function loadPdfPage(string $bytes, int $page, #[\SensitiveParameter] string $password = ''): PdfPageHandle;
}
