<?php

declare(strict_types=1);

namespace Papercut\Export;

use Papercut\Exception\UnsupportedFeature;

/** Collects what an exporter could not render faithfully; strict mode turns drops into exceptions. */
final class Fidelity
{
    /** @var list<FidelityReport> */
    private array $reports = [];

    /** True when the callback takes one parameter and so cannot be handed the report. */
    private readonly bool $messageOnly;

    public function __construct(
        private readonly string $format,
        private readonly ExportOptions $options,
    ) {
        $onWarning = $options->onWarning();
        $reflection = $onWarning === null ? null : new \ReflectionFunction($onWarning);
        $this->messageOnly = $reflection !== null && $reflection->getNumberOfParameters() === 1 && !$reflection->isVariadic();
    }

    public function approximated(string $feature, string $path): void
    {
        $this->record(new FidelityReport(FidelityLevel::Approximated, $this->format, $feature, $path));
    }

    /** @throws UnsupportedFeature in strict mode */
    public function dropped(string $feature, string $path): void
    {
        $report = new FidelityReport(FidelityLevel::Dropped, $this->format, $feature, $path);
        if ($this->options->strict()) {
            throw UnsupportedFeature::from($report);
        }
        $this->record($report);
    }

    /** A plain warning that is not a fidelity report; the callback is handed the message only. */
    public function warn(string $message): void
    {
        $this->options->onWarning()?->__invoke($message);
    }

    /** @return list<FidelityReport> */
    public function reports(): array
    {
        return $this->reports;
    }

    private function record(FidelityReport $report): void
    {
        $this->reports[] = $report;
        $onWarning = $this->options->onWarning();
        if ($onWarning !== null) {
            $this->messageOnly ? $onWarning($report->message()) : $onWarning($report->message(), $report);
        }
    }
}
