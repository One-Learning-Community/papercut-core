<?php

declare(strict_types=1);

namespace Quoin\Export;

use Quoin\Html\{FontAnswer, FontRequest, FontSource};
use Quoin\Image;
use Quoin\Style\Theme;
use Quoin\Text\{FontFace, FontSet};

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
        private bool $inlineSvgs = false,
    ) {
        parent::__construct($theme, $fonts, $onWarning, $strict);
        $this->imageUrl = $imageUrl;
        $this->fontUrl = $fontUrl;
        $this->fontSources = $fontSources;
    }

    /** Whether the export is a whole page (head, page size and print rules) rather than a fragment. */
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

    /** Whether an SVG is written as inline markup, so its text uses the page's fonts, instead of as an img. Use it only for trusted SVG. */
    public function inlineSvgs(): bool
    {
        return $this->inlineSvgs;
    }

    /** A copy that writes a whole page (head, page size and print rules) or only the fragment. */
    public function withFullPage(bool $on): self
    {
        return new self($this->theme(), $this->fonts(), $this->onWarning(), $this->strict(), $on, $this->fontMode, $this->imageUrl, $this->fontUrl, $this->fontSources, $this->inlineSvgs);
    }

    /** A copy that writes SVG as inline markup (true) or as images (false). Use it only for trusted SVG. */
    public function withInlineSvgs(bool $on): self
    {
        return new self($this->theme(), $this->fonts(), $this->onWarning(), $this->strict(), $this->fullPage, $this->fontMode, $this->imageUrl, $this->fontUrl, $this->fontSources, $on);
    }

    public static function from(ExportOptions $o): self
    {
        return $o instanceof self ? $o : new self($o->theme(), $o->fonts(), $o->onWarning(), $o->strict());
    }
}
