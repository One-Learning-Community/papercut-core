<?php

declare(strict_types=1);

namespace Papercut\Export;

use Papercut\Document;

interface Exporter
{
    public function export(Document $doc, ExportOptions $options): Output;

    public function defaultOptions(): ExportOptions;
}
