<?php

declare(strict_types=1);

namespace Papercut;

use Papercut\Concerns\{HasNotes, HasStyle};
use Papercut\Style\Style;

final class Text implements Styled, Annotated
{
    use HasStyle;
    use HasNotes;

    private const array PRESETS = ['h1', 'h2', 'h3', 'h4', 'p'];

    /** @var list<Span> */
    private array $spans = [];
    private ?string $preset = null;
    private bool $htmlCode = false;
    private ?string $htmlCodeLanguage = null;

    private function __construct()
    {
    }

    public static function make(string $text = ''): self
    {
        return self::spans(Span::text($text));
    }

    public static function spans(Span ...$spans): self
    {
        $t = new self();
        $t->spans = array_values($spans);
        return $t;
    }

    public static function markup(string $markup): self
    {
        return self::spans(...Text\InlineMarkup::parse($markup));
    }

    public static function h1(string $text): self
    {
        return self::make($text)->withPreset('h1');
    }

    public static function h2(string $text): self
    {
        return self::make($text)->withPreset('h2');
    }

    public static function h3(string $text): self
    {
        return self::make($text)->withPreset('h3');
    }

    public static function h4(string $text): self
    {
        return self::make($text)->withPreset('h4');
    }

    public static function p(string $text): self
    {
        return self::make($text)->withPreset('p');
    }

    public function preset(): ?string
    {
        return $this->preset;
    }

    /**
     * Hints that HTML export wraps the text in `<code>` (with `class="language-x"` when a language is
     * given) inside its `<pre>`; PDF and Word ignore it.
     *
     * @internal Set by CodeBlock; not public API before v1.
     */
    public function htmlCode(?string $language = null): self
    {
        $clone = clone $this;
        $clone->htmlCode = true;
        $clone->htmlCodeLanguage = $language === null || $language === '' ? null : $language;
        return $clone;
    }

    public function isHtmlCode(): bool
    {
        return $this->htmlCode;
    }

    public function htmlCodeLanguage(): ?string
    {
        return $this->htmlCodeLanguage;
    }

    public function withPreset(string $name): self
    {
        if (!in_array($name, self::PRESETS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown text preset "%s"; expected one of: %s.',
                $name,
                implode(', ', self::PRESETS),
            ));
        }
        $clone = clone $this;
        $clone->preset = $name;
        return $clone;
    }

    /**
     * @return list<Span>
     */
    public function spanList(): array
    {
        return $this->spans;
    }

    /** Adds spans after this text's own; an appended Text gives its spans and notes, not its own Text-level style. */
    public function append(string|Span|Text ...$more): self
    {
        $clone = clone $this;
        foreach ($more as $m) {
            if ($m instanceof Text) {
                array_push($clone->spans, ...$m->spans);
                array_push($clone->notes, ...$m->notes);
                continue;
            }
            $clone->spans[] = is_string($m) ? Span::text($m) : $m;
        }
        return $clone;
    }

    public function debugName(): string
    {
        return 'text';
    }
}
