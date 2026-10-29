<?php

declare(strict_types=1);

namespace Papercut\Concerns;

use InvalidArgumentException;
use Papercut\Exception\InvalidVariant;

/**
 * Variants for decorators: `variant('large')` makes every part the decorator styles also apply
 * `<part>.large` when the theme defines it. A variant the theme does not style is a no-op.
 */
trait HasVariants
{
    /** @var list<string> */
    private array $variants = [];

    /** Adds one or more space-separated variant words, each made of a-z, 0-9 and hyphens. */
    public function variant(string $names): static
    {
        $words = preg_split('/\s+/', trim($names), -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false || $words === []) {
            throw new InvalidArgumentException('A variant must not be empty.');
        }
        foreach ($words as $word) {
            if (preg_match('/^[a-z0-9-]+$/', $word) !== 1) {
                throw new InvalidArgumentException("A variant is a lowercase word of a-z, 0-9 and hyphens, got '$word'.");
            }
        }
        $segments = [];
        foreach ($this->partNames() as $part) {
            array_push($segments, ...explode('.', $part));
        }
        foreach ($words as $word) {
            if (in_array($word, $segments, true)) {
                throw new InvalidVariant("The variant '$word' collides with a part name of this decorator (a name segment of: " . implode(', ', $this->partNames()) . '); pick another word.');
            }
        }
        foreach ($words as $word) {
            if (in_array($word, $this->toneNames(), true)) {
                throw new InvalidVariant("The variant '$word' is one of this decorator's built-in tones (" . implode(', ', $this->toneNames()) . "); use tone('$word') for the tone's colours, or pick another variant word.");
            }
        }
        $clone = clone $this;
        array_push($clone->variants, ...$words);
        return $clone;
    }

    /**
     * The built-in tone names a variant word may not equal, as the tone's colours would then apply
     * through the variant. A decorator with built-in tones overrides this.
     *
     * @return list<string>
     */
    protected function toneNames(): array
    {
        return [];
    }

    /** @return list<string> */
    public function variants(): array
    {
        return $this->variants;
    }

    /**
     * A part's style names: the built-in name, an optional tone name, then an optional name per variant.
     * Names ending in `?` are skipped by the theme when it does not define them.
     */
    protected function part(string $name, ?string $tone = null): string
    {
        $names = $tone === null ? $name : "$name $name.$tone";
        foreach ($this->variants as $variant) {
            $names .= " $name.$variant?";
        }
        return $names;
    }
}
