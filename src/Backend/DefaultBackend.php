<?php

declare(strict_types=1);

namespace Papercut\Backend;

use Papercut\Backend\PhpPdf\PhpPdfBackend;

/** The one place that names the backend every exporter uses unless told otherwise. */
final class DefaultBackend
{
    /** The backend every exporter uses unless told otherwise: a new PhpPdfBackend. */
    public static function make(string|false|null $kerningCacheDir = null): Backend
    {
        return new PhpPdfBackend($kerningCacheDir);
    }
}
