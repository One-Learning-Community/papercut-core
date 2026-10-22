<?php

declare(strict_types=1);

namespace Papercut\Export;

use Papercut\Html\{FontAnswer, FontRequest, FontSource};
use Papercut\Image;
use Papercut\Style\Theme;
use Papercut\Text\{FontFace, FontSet};

final readonly class HtmlOptions extends ExportOptions
{
    /** @var (\Closure(Image): ?string)|null */
    private ?\Closure $imageUrl;
    /** @var (\Closure(FontFace): ?string)|null */
    private ?\Closure $fontUrl;
    /** @var list<FontSource|\Closure(FontRequest): ?FontAnswer> */
    private array $fontSources;

    /**
     * @param (\Closure(string, ?FidelityReport=): void)|null $onWarning
     * @param (\Closure(Image): ?string)|null $imageUrl a null result embeds the image as a data URI
     * @param (\Closure(FontFace): ?string)|null $fontUrl used by FontMode::Link; a null result skips that face
     * @param list<FontSource|\Closure(FontRequest): ?FontAnswer> $fontSources used by FontMode::Sources, asked in order for each family; the first usable answer wins
     */
    public function __construct(
        ?Theme $theme = null,
        ?FontSet $fonts = null,
        ?\Closure $onWarning = null,
        bool $strict = false,
        private bool $fullPage = false,
        private FontMode $fontMode = FontMode::None,
        ?\Closure $imageUrl = null,
        ?\Closure $fontUrl = null,
        array $fontSources = [],
    ) {
        parent::__construct($theme, $fonts, $onWarning, $strict);
        $this->imageUrl = $imageUrl;
        $this->fontUrl = $fontUrl;
        $this->fontSources = $fontSources;
    }

    public function fullPage(): bool
    {
        return $this->fullPage;
    }

    public function fontMode(): FontMode
    {
        return $this->fontMode;
    }

    /** @return (\Closure(Image): ?string)|null */
    public function imageUrl(): ?\Closure
    {
        return $this->imageUrl;
    }

    /** @return (\Closure(FontFace): ?string)|null */
    public function fontUrl(): ?\Closure
    {
        return $this->fontUrl;
    }

    /** @return list<FontSource|\Closure(FontRequest): ?FontAnswer> */
    public function fontSources(): array
    {
        return $this->fontSources;
    }

    public function withFullPage(bool $on): self
    {
        return new self($this->theme(), $this->fonts(), $this->onWarning(), $this->strict(), $on, $this->fontMode, $this->imageUrl, $this->fontUrl, $this->fontSources);
    }

    public static function from(ExportOptions $o): self
    {
        return $o instanceof self ? $o : new self($o->theme(), $o->fonts(), $o->onWarning(), $o->strict());
    }
}
