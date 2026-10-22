<?php

declare(strict_types=1);

namespace Papercut\Exception;

use Papercut\Export\FidelityReport;
use Papercut\PapercutException;

final class UnsupportedFeature extends PapercutException
{
    private function __construct(public readonly FidelityReport $report)
    {
        parent::__construct(sprintf('Strict mode: %s cannot render %s at %s.', $report->format, $report->feature, $report->path));
    }

    public static function from(FidelityReport $report): self
    {
        return new self($report);
    }
}
