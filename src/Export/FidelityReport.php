<?php

declare(strict_types=1);

namespace Papercut\Export;

final readonly class FidelityReport
{
    public function __construct(
        public FidelityLevel $level,
        public string $format,
        public string $feature,
        public string $path,
    ) {
    }

    public function message(): string
    {
        $verb = $this->level === FidelityLevel::Dropped ? 'dropped' : 'approximated';

        return sprintf('%s: %s %s at %s', $this->format, $verb, $this->feature, $this->path);
    }
}
