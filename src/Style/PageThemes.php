<?php

declare(strict_types=1);

namespace Papercut\Style;

use Papercut\{Document, Page};

/**
 * The theme each page renders with: the base theme with the document's override, then the
 * page's own. Pages without an override share the document's Theme and StyleResolver.
 */
final class PageThemes
{
    private readonly Theme $document;
    private readonly ?ThemeOverride $documentOverride;
    /** @var \WeakMap<Page, Theme> */
    private \WeakMap $themes;
    /** @var \WeakMap<Theme, StyleResolver> */
    private \WeakMap $resolvers;

    private function __construct(Theme $base, Document $doc)
    {
        $this->documentOverride = $doc->themeOverride();
        $this->document = $this->documentOverride === null ? $base : $base->withOverride($this->documentOverride);
        $this->themes = new \WeakMap();
        $this->resolvers = new \WeakMap();
    }

    public static function for(Theme $base, Document $doc): self
    {
        return new self($base, $doc);
    }

    public function document(): Theme
    {
        return $this->document;
    }

    public function theme(Page $page): Theme
    {
        if (isset($this->themes[$page])) {
            return $this->themes[$page];
        }
        $override = $page->themeOverride();
        $theme = $override === null || $override->isEmpty()
            ? $this->document
            : $this->document->withOverride($override);
        return $this->themes[$page] = $theme;
    }

    public function styles(Page $page): StyleResolver
    {
        return $this->resolverFor($this->theme($page));
    }

    public function documentStyles(): StyleResolver
    {
        return $this->resolverFor($this->document);
    }

    private function resolverFor(Theme $theme): StyleResolver
    {
        return $this->resolvers[$theme] ??= new StyleResolver($theme);
    }
}
