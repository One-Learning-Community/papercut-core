<?php

declare(strict_types=1);

namespace Papercut\Export;

use Papercut\Document;

/** @internal Part of Papercut's engine, not its public API. It may change in any release. */
interface Exporter
{
    public function export(Document $doc, ExportOptions $options): Output;

    public function defaultOptions(): ExportOptions;
}
