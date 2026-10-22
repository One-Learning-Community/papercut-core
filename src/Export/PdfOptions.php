<?php

declare(strict_types=1);

namespace Papercut\Export;

use Papercut\Backend\Backend;
use Papercut\Backend\DefaultBackend;
use Papercut\OverflowMode;
use Papercut\Style\Theme;
use Papercut\Text\FontSet;

final readonly class PdfOptions extends ExportOptions
{
    private ?Backend $backend;

    /**
     * @param (\Closure(string, ?FidelityReport=): void)|null $onWarning
     * @param string|false|null $cacheDir directory for the default backend's font kerning cache
     *        (null = sys_get_temp_dir()/papercut-cache-<uid>, false = no disk cache); ignored when $backend is given
     */
    public function __construct(
        ?Theme $theme = null,
        ?FontSet $fonts = null,
        ?\Closure $onWarning = null,
        bool $strict = false,
        ?Backend $backend = null,
        private OverflowMode $overflow = OverflowMode::Throw,
        private string|false|null $cacheDir = null,
    ) {
        parent::__construct($theme, $fonts, $onWarning, $strict);
        $this->backend = $backend;
    }

    /**
     * Returns the given backend, or a new PhpPdfBackend on each call.
     * Callers should call this once and reuse the result.
     */
    public function backend(): Backend
    {
        return $this->backend ?? DefaultBackend::make($this->cacheDir);
    }

    public function overflow(): OverflowMode
    {
        return $this->overflow;
    }

    public function cacheDir(): string|false|null
    {
        return $this->cacheDir;
    }

    public static function from(ExportOptions $o): self
    {
        return $o instanceof self ? $o : new self($o->theme(), $o->fonts(), $o->onWarning(), $o->strict());
    }
}
