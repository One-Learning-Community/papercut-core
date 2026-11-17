<?php

declare(strict_types=1);

namespace Quoin\Export;

use Quoin\Document;

/** @internal Engine detail, not public API. It may change in any release. */
interface Exporter
{
    public function export(Document $doc, ExportOptions $options): Output;

    public function defaultOptions(): ExportOptions;
}
