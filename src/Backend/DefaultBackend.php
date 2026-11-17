<?php

declare(strict_types=1);

namespace Quoin\Backend;

use Quoin\Backend\PhpPdf\PhpPdfBackend;

/**
 * The one place that names the backend every exporter uses unless told otherwise.
 *
 * @internal Part of the PDF writer. Custom backends aren't supported, and it may change in any release.
 */
final class DefaultBackend
{
    /** The backend every exporter uses unless told otherwise: a new PhpPdfBackend. */
    public static function make(string|false|null $cacheDir = null): Backend
    {
        return new PhpPdfBackend($cacheDir);
    }
}
