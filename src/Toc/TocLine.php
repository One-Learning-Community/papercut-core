<?php

declare(strict_types=1);

namespace Papercut\Toc;

use Papercut\{Styled, Text};
use Papercut\Style\Style;

/**
 * One entry of a table of contents: the heading's text with room for its page
 * number. Synthesized by the table of contents layout; it carries the entry's
 * styles, so the block layouter applies its margins and the layouter its padding,
 * while the title text inherits the rest.
 *
 * @internal
 */
final class TocLine implements Styled
{
    public readonly Text $title;

    public function __construct(public readonly TocEntry $entry, public readonly bool $leaders)
    {
        $this->title = Text::make($entry->text);
    }

    private static function names(TocEntry $entry): string
    {
        return 'toc.entry toc.level-' . max(1, min(4, $entry->level));
    }

    public function style(): Style
    {
        return new Style();
    }

    public function styleName(): string
    {
        return self::names($this->entry);
    }

    public function apply(Style|string $style): static
    {
        return $this;
    }

    public function debugName(): string
    {
        return 'toc-line';
    }
}
