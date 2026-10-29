<?php

declare(strict_types=1);

namespace Papercut\Decorator;

use Papercut\Component;
use Papercut\Style\Style;

/**
 * A Component that renders a themed container from ordinary nodes (View, Text, ...).
 *
 * The contract every decorator follows:
 * - It is a final, immutable builder: a private constructor, a static constructor (make(), or named constructors such as ChoiceList::radio()), and with-style setters that return clones.
 * - It uses HasStyle, and every style modifier (padding(), apply(), attributes(), ...) applies to its OUTER box.
 * - The outer box is built as View::make(...)->apply(trim('<base names> ' . ($this->styleName() ?? '')))->apply($this->style()),
 *   so precedence runs built-in decorator style < tone < variant < the user's named style < explicit style modifiers.
 * - It uses HasVariants: every built-in name goes through part(), which appends optional `<name>.<variant>?` names.
 *
 * Implementations satisfy the style methods by using Papercut\Concerns\HasStyle.
 */
interface Decorator extends Component
{
    public function style(): Style;

    public function styleName(): ?string;

    /** Adds space-separated variant words; each part also applies `<part>.<variant>` when the theme defines it. */
    public function variant(string $names): static;

    /** @return list<string> */
    public function variants(): array;

    /**
     * Every built-in style name this decorator can apply, tone-less; each also takes `.<variant>` suffixes.
     *
     * @return list<string>
     */
    public function partNames(): array;

    public function apply(Style|string $style): static;

    /**
     * @param array<string, string|int|float|bool|object> $attrs
     */
    public function attributes(array $attrs): static;
}
