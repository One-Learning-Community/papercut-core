<?php

declare(strict_types=1);

namespace Papercut\Code;

/**
 * How code blocks are highlighted: the highlighter, the default theme and a default variant.
 * A null highlighter resolves lazily to one shared Phiki highlighter when phiki/phiki is installed;
 * false means "no highlighter" (blocks render plain and report the package as missing).
 */
final readonly class CodeHighlighting
{
    public function __construct(
        private Highlighter|false|null $highlighterSetting = null,
        public string $theme = CodeTheme::DEFAULT,
        public ?string $variant = null,
    ) {
        if ($variant !== null && $variant !== 'strip' && $variant !== 'caption') {
            throw new \InvalidArgumentException("The code highlighting variant must be null, 'strip' or 'caption', got '$variant'.");
        }
    }

    public static function default(): self
    {
        static $default = null;
        return $default ??= new self();
    }

    public function highlighter(): ?Highlighter
    {
        if ($this->highlighterSetting === false) {
            return null;
        }
        if ($this->highlighterSetting instanceof Highlighter) {
            return $this->highlighterSetting;
        }
        static $shared = null;
        if ($shared === null && PhikiHighlighter::available()) {
            $shared = new PhikiHighlighter();
        }
        return $shared;
    }
}
