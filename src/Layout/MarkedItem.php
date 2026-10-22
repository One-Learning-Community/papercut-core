<?php

declare(strict_types=1);

namespace Papercut\Layout;

use Papercut\{ListItem, Styled, Text, View};
use Papercut\Style\Style;

/**
 * A ListItem paired with its marker label, the list's gutter width and the
 * gap between the marker's right edge and the content.
 * Synthesized by ListLayouter; it styles itself as its item, so the block
 * layouter applies the item's margins and computes its style.
 *
 * @internal
 */
final class MarkedItem implements Styled
{
    /** The item's children as a column; the item's computed style is applied to it directly. */
    public readonly View $content;
    /** Null when the label is empty. */
    public readonly ?Text $marker;

    public function __construct(public readonly ListItem $item, public readonly string $label, public readonly float $gutter, public readonly float $gap)
    {
        $this->content = View::make(...$item->children());
        $this->marker = $label === '' ? null : Text::make($label);
    }

    public function style(): Style
    {
        return $this->item->style();
    }

    public function styleName(): ?string
    {
        return $this->item->styleName();
    }

    public function apply(Style|string $style): static
    {
        return new self($this->item->apply($style), $this->label, $this->gutter, $this->gap);
    }

    public function debugName(): string
    {
        return 'item';
    }
}
