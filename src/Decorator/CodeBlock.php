<?php

declare(strict_types=1);

namespace Papercut\Decorator;

use Papercut\Code\{CodeColors, CodeToken, CodeHighlighting, CodeTheme, HighlightedCode, Highlighter, UnknownLanguage, UnknownTheme};
use Papercut\Concerns\{HasStyle, HasVariants};
use Papercut\Exception\InvalidVariant;
use Papercut\Export\FidelityLevel;
use Papercut\Markup\Note;
use Papercut\{Span, Text, View};
use Papercut\Style\Style;

/**
 * A block of source code with syntax highlighting, a muted language label and optional
 * `strip` (label in a bar) and `caption` (label above the box) variants.
 *
 * Without a highlighter, or for an unknown language or theme, the code is laid out plain and a
 * Dropped note reaches the fidelity report.
 */
final class CodeBlock implements Decorator
{
    use HasStyle;
    use HasVariants {
        variant as private addVariant;
    }

    /** Words that mean "no highlighting wanted, but a label is fine". */
    private const array PLAIN = ['text', 'plain', 'plaintext', 'txt'];
    private const array NO_HIGHLIGHT = ['nohighlight', 'no-highlight'];

    private string $code = '';
    private ?string $language = null;
    private ?string $theme = null;
    private string|false|null $label = null;
    private ?CodeHighlighting $highlighting = null;
    private ?View $view = null;

    private function __construct()
    {
    }

    public function __clone()
    {
        $this->view = null;
    }

    public static function make(string $code): self
    {
        $block = new self();
        $code = str_replace(["\r\n", "\r"], "\n", $code);
        // One final newline ends the last line; any further ones are the author's blank lines.
        $block->code = str_ends_with($code, "\n") ? substr($code, 0, -1) : $code;
        return $block;
    }

    public function language(?string $language): self
    {
        $clone = clone $this;
        $language = $language === null ? null : trim($language);
        $clone->language = $language === '' ? null : $language;
        return $clone;
    }

    public function theme(?string $theme): self
    {
        $clone = clone $this;
        $clone->theme = $theme;
        return $clone;
    }

    /** Null is the default label (the language's name), false turns the label off. */
    public function label(string|false|null $label): self
    {
        $clone = clone $this;
        $clone->label = $label;
        return $clone;
    }

    public function highlighting(CodeHighlighting $highlighting): self
    {
        $clone = clone $this;
        $clone->highlighting = $highlighting;
        return $clone;
    }

    public function variant(string $names): static
    {
        $clone = $this->addVariant($names);
        self::assertCompatible($clone->variants());
        return $clone;
    }

    /** @return list<string> */
    public function partNames(): array
    {
        return ['code-block', 'code-block.code', 'code-block.label', 'code-block.bar'];
    }

    public function render(): View
    {
        return $this->view ??= $this->build();
    }

    /** @param list<string> $variants */
    private static function assertCompatible(array $variants): void
    {
        if (in_array('strip', $variants, true) && in_array('caption', $variants, true)) {
            throw new InvalidVariant("The code block variants 'strip' and 'caption' both place the label and cannot be combined.");
        }
    }

    private function build(): View
    {
        $config = $this->highlighting ?? CodeHighlighting::default();
        $block = $this;
        if ($this->variants() === [] && $config->variant !== null) {
            $block = $this->addVariant($config->variant);
        }
        self::assertCompatible($block->variants());

        $notes = [];
        $language = $this->language;
        $lower = $language === null ? null : strtolower($language);
        $wantsHighlight = $lower !== null && !in_array($lower, self::NO_HIGHLIGHT, true) && !in_array($lower, self::PLAIN, true);
        $highlighter = $config->highlighter();
        $theme = $this->theme ?? $config->theme;

        $displayName = null;
        $highlighted = null;
        if ($wantsHighlight && $highlighter === null) {
            $notes[] = new Note(FidelityLevel::Dropped, 'syntax highlighting (install phiki/phiki)');
        } elseif ($wantsHighlight) {
            [$highlighted, $theme] = $this->highlightOrNull($highlighter, $this->code, (string) $language, $theme, $notes);
            $displayName = $highlighter->displayName((string) $language);
        }
        if ($highlighted === null) {
            [$highlighted, $theme] = $this->plain($highlighter, $this->code, $theme, $notes, $wantsHighlight);
        }
        $coloured = $wantsHighlight && !self::hasNote($notes, 'unknown language');

        $background = $highlighted->background;
        $colors = CodeColors::for($background);
        $labelText = $this->labelText($lower, $language, $displayName);

        $spans = self::spans($highlighted, $coloured);
        $code = Text::spans(...$spans)->htmlCode($language)->apply($block->part('code-block.code'));

        $strip = in_array('strip', $block->variants(), true);
        $caption = in_array('caption', $block->variants(), true);

        $labelNode = null;
        if ($labelText !== null) {
            $labelNode = Text::make($labelText)
                ->apply($block->part('code-block.label'))
                ->apply(Style::make(color: $colors->label));
        }

        $boxStyle = Style::make(
            background: $background,
            color: $highlighted->foreground,
            borderColor: $colors->border,
        );

        if ($caption) {
            // The rule is the code Text's left border (code-block.code.caption), so its colour goes there.
            $code = $code->apply(Style::make(borderColor: $colors->rule));
            $box = View::make($code)->apply($block->part('code-block'))->apply($boxStyle)->apply($this->style());
            $parts = $labelNode === null ? [$box] : [$labelNode->keepWithNext(true), $box];
            $view = View::make(...$parts);
            if ($this->styleName() !== null) {
                $view = $view->apply($this->styleName());
            }
        } else {
            $parts = [];
            if ($labelNode !== null && $strip) {
                $parts[] = View::make($labelNode)
                    ->apply($block->part('code-block.bar'))
                    ->apply(Style::make(background: $colors->bar, borderColor: $colors->border))
                    ->keepWithNext(true);
            } elseif ($labelNode !== null) {
                $parts[] = $labelNode->keepWithNext(true);
            }
            $parts[] = $code;
            $view = View::make(...$parts)
                ->apply(trim($block->part('code-block') . ' ' . ($this->styleName() ?? '')))
                ->apply($boxStyle)
                ->apply($this->style());
        }

        return $notes === [] ? $view : $view->withNotes(...$notes);
    }

    private function labelText(?string $lower, ?string $language, ?string $displayName): ?string
    {
        if ($this->label === false || $this->label === '') {
            return null;
        }
        if ($this->label !== null) {
            return $this->label;
        }
        if ($language === null || in_array($lower, self::NO_HIGHLIGHT, true)) {
            return null;
        }
        if (in_array($lower, self::PLAIN, true)) {
            return 'TEXT';
        }
        return strtoupper($displayName ?? $language);
    }

    /**
     * Highlights, retrying with the default theme when the theme is unknown.
     *
     * @param list<Note> $notes
     * @return array{0: ?HighlightedCode, 1: string} null when the language is unknown
     */
    private function highlightOrNull(Highlighter $highlighter, string $code, string $language, string $theme, array &$notes): array
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                return [$highlighter->highlight($code, $language, $theme), $theme];
            } catch (UnknownLanguage) {
                $notes[] = new Note(FidelityLevel::Dropped, "syntax highlighting (unknown language '$language')");
                return [null, $theme];
            } catch (UnknownTheme) {
                if ($theme === CodeTheme::DEFAULT) {
                    throw new \RuntimeException('The default code theme is unknown to the highlighter.');
                }
                $notes[] = new Note(FidelityLevel::Dropped, "syntax highlighting (unknown theme '$theme'; the default theme was used)");
                $theme = CodeTheme::DEFAULT;
            }
        }
        throw new \LogicException('unreachable');
    }

    /**
     * The code as uncoloured lines on the theme's background.
     *
     * @param list<Note> $notes
     * @return array{0: HighlightedCode, 1: string}
     */
    private function plain(?Highlighter $highlighter, string $code, string $theme, array &$notes, bool $wantsHighlight): array
    {
        $lines = [];
        foreach ($code === '' ? [] : explode("\n", str_replace(["\r\n", "\r"], "\n", $code)) as $line) {
            $lines[] = $line === '' ? [] : [new CodeToken($line)];
        }
        $colors = null;
        if ($highlighter !== null) {
            for ($attempt = 0; $attempt < 2 && $colors === null; $attempt++) {
                try {
                    $themed = $highlighter->highlight('', 'text', $theme);
                    $colors = ['background' => $themed->background, 'foreground' => $themed->foreground];
                } catch (UnknownTheme) {
                    if (!self::hasNote($notes, 'unknown theme') && $theme !== CodeTheme::DEFAULT) {
                        $notes[] = new Note(FidelityLevel::Dropped, "syntax highlighting (unknown theme '$theme'; the default theme was used)");
                    }
                    $theme = CodeTheme::DEFAULT;
                } catch (UnknownLanguage) {
                    break; // a highlighter without a 'text' grammar: use the default theme's colours
                }
            }
        }
        $colors ??= CodeTheme::editorColors(CodeTheme::defaultPath());
        return [new HighlightedCode($lines, $colors['background'], $colors['foreground']), $theme];
    }

    /** @param list<Note> $notes */
    private static function hasNote(array $notes, string $fragment): bool
    {
        foreach ($notes as $note) {
            if (str_contains($note->feature, $fragment)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<Span> */
    private static function spans(HighlightedCode $code, bool $coloured): array
    {
        $spans = [];
        foreach ($code->lines as $i => $line) {
            if ($i > 0) {
                $spans[] = Span::break();
            }
            foreach ($line as $token) {
                $style = $coloured
                    ? Style::make(
                        color: $token->color,
                        fontWeight: $token->bold ? 700 : null,
                        italic: $token->italic ? true : null,
                        underline: $token->underline ? true : null,
                    )
                    : null;
                $spans[] = Span::text($token->text, $style);
            }
        }
        return $spans === [] ? [Span::text('')] : $spans;
    }
}
