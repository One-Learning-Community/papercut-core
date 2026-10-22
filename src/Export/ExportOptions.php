<?php

declare(strict_types=1);

namespace Papercut\Export;

use Papercut\Style\Theme;
use Papercut\Text\FontSet;

readonly class ExportOptions
{
    private ?Theme $theme;
    private ?FontSet $fonts;
    /** @var (\Closure(string, ?FidelityReport=): void)|null */
    private ?\Closure $onWarning;

    /** @param (\Closure(string, ?FidelityReport=): void)|null $onWarning */
    public function __construct(
        ?Theme $theme = null,
        ?FontSet $fonts = null,
        ?\Closure $onWarning = null,
        private bool $strict = false,
    ) {
        $this->theme = $theme;
        $this->fonts = $fonts;
        $this->onWarning = $onWarning;
    }

    public function strict(): bool
    {
        return $this->strict;
    }

    public function theme(): Theme
    {
        return $this->theme ?? Theme::default();
    }

    public function fonts(): FontSet
    {
        return $this->fonts ?? new FontSet();
    }

    /** @return (\Closure(string, ?FidelityReport=): void)|null */
    public function onWarning(): ?\Closure
    {
        return $this->onWarning;
    }
}
