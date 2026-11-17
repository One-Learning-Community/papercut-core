<?php

declare(strict_types=1);

namespace Quoin\Exception;

use Quoin\Export\FidelityReport;
use Quoin\QuoinException;

final class UnsupportedFeature extends QuoinException
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
