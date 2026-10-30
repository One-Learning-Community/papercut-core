<?php

declare(strict_types=1);

namespace Papercut\Code;

interface Highlighter
{
    /**
     * @throws UnknownLanguage
     * @throws UnknownTheme
     */
    public function highlight(string $code, string $language, string $theme): HighlightedCode;

    /** The language's display name, or null when the language is unknown. */
    public function displayName(string $language): ?string;
}
