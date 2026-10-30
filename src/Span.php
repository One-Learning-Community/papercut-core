<?php

declare(strict_types=1);

namespace Papercut;

use Papercut\Style\Style;

final readonly class Span
{
    public function __construct(
        public string $text,
        public Style $style = new Style(),
        public ?string $href = null,
        public bool $lineBreak = false,
        /** A theme style the span takes first; $style is the span's own overrides on top of it. */
        public ?string $styleName = null,
        /** The style the span's named style sits on: what was set outside it (so the named style wins over it, and $style wins over both). */
        public ?Style $base = null,
    ) {
    }

    public static function text(string $text, ?Style $style = null): self
    {
        return new self($text, $style ?? new Style());
    }

    /** Literal text in the theme's `code` style: the same span as `<code>` in markup and `<x-doc::code>` in Blade. */
    public static function code(string $text): self
    {
        return new self($text, new Style(), null, false, 'code');
    }

    public static function break(): self
    {
        return new self('', new Style(), null, true);
    }
}
