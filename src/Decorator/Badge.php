<?php

declare(strict_types=1);

namespace Papercut\Decorator;

use InvalidArgumentException;
use Papercut\{Component, Node, Text, View};
use Papercut\Concerns\{HasStyle, HasVariants};

/**
 * A small rounded label in a tone; block-level, sized to its text.
 */
final class Badge implements Decorator
{
    use HasStyle;
    use HasVariants;

    private Node|Component $content;
    private string $tone = 'neutral';

    private function __construct()
    {
    }

    public static function make(string|Node|Component $content): self
    {
        $badge = new self();
        $badge->content = is_string($content) ? Text::make($content) : $content;
        return $badge;
    }

    /** Tones are free strings; `badge.<tone>` must exist in the theme. */
    public function tone(string $tone): self
    {
        $tone = trim($tone);
        if ($tone === '') {
            throw new InvalidArgumentException('Badge tone must not be empty.');
        }
        if (preg_match('/\s/', $tone) === 1) {
            throw new InvalidArgumentException("Badge tone must be a single word, got '$tone'.");
        }
        if (str_contains($tone, '?')) {
            throw new InvalidArgumentException("Badge tone must not contain '?', got '$tone'.");
        }
        $clone = clone $this;
        $clone->tone = $tone;
        return $clone;
    }

    public function toneName(): string
    {
        return $this->tone;
    }

    /** @return list<string> */
    protected function toneNames(): array
    {
        return ['neutral', 'note', 'tip', 'warning', 'danger'];
    }

    /** @return list<string> */
    public function partNames(): array
    {
        return ['badge'];
    }

    public function render(): View
    {
        return View::make($this->content)
            ->apply(trim($this->part('badge', $this->tone) . ' ' . ($this->styleName() ?? '')))
            ->apply($this->style());
    }
}
