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
     * @param string|false|null $cacheDir directory for the default backend's font cache
     *        (null = sys_get_temp_dir()/papercut-cache-<uid>, false = no disk cache); ignored when $backend is given
     * @param bool $bookmarks false leaves the PDF without a bookmarks panel, even for headings
     * @param Encryption|null $encryption password protection and permissions; null writes an unencrypted PDF
     */
    public function __construct(
        ?Theme $theme = null,
        ?FontSet $fonts = null,
        ?\Closure $onWarning = null,
        bool $strict = false,
        ?Backend $backend = null,
        private OverflowMode $overflow = OverflowMode::Throw,
        private string|false|null $cacheDir = null,
        private bool $bookmarks = true,
        private ?Encryption $encryption = null,
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

    /** A copy that encrypts with $encryption, or writes an unencrypted PDF for null. */
    public function withEncryption(?Encryption $encryption): self
    {
        return new self($this->theme(), $this->fonts(), $this->onWarning(), $this->strict(), $this->backend, $this->overflow, $this->cacheDir, $this->bookmarks, $encryption);
    }

    public function encryption(): ?Encryption
    {
        return $this->encryption;
    }

    public function bookmarks(): bool
    {
        return $this->bookmarks;
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
