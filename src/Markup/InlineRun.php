<?php

declare(strict_types=1);

namespace Papercut\Markup;

use Papercut\Span;
use Papercut\Style\Style;

/**
 * Accumulates the inline content of one block, collapsing whitespace the way
 * a browser does: runs of ASCII whitespace become one space, spaces at the
 * start and end of a line are trimmed, U+00A0 is kept as is, and zero-width
 * characters are removed.
 *
 * @internal
 */
final class InlineRun
{
    private const string NBSP = "\u{00A0}";

    /** @var list<Span> */
    private array $spans = [];

    public function text(string $text, Style $style, ?string $href): void
    {
        $text = str_replace(["\u{FEFF}", "\u{200B}"], '', $text);
        $text = preg_replace('/[ \t\n\r\f]+/', ' ', $text) ?? $text;
        if (str_starts_with($text, ' ') && $this->atLineStartOrAfterSpace()) {
            $text = substr($text, 1);
        }
        if ($text !== '') {
            $this->spans[] = new Span($text, $style, $href);
        }
    }

    public function break(): void
    {
        $this->trimEnd();
        $this->spans[] = Span::break();
    }

    /**
     * Ends the run and starts a new one. Returns the paragraph's spans (a trailing
     * break dropped, as browsers do) and whether the run held only blank-line
     * content: non-breaking spaces and/or breaks. Both empty and false means the
     * run had nothing at all.
     *
     * @return array{list<Span>, bool}
     */
    public function take(): array
    {
        $this->trimEnd();
        $spans = $this->spans;
        $this->spans = [];

        $visible = false;
        $marks = false;
        foreach ($spans as $span) {
            if ($span->lineBreak) {
                $marks = true;
            } elseif (trim($span->text, ' ' . self::NBSP) !== '') {
                $visible = true;
            } else {
                $marks = $marks || str_contains($span->text, self::NBSP);
            }
        }
        if (!$visible) {
            return [[], $marks];
        }

        if ($spans[count($spans) - 1]->lineBreak) {
            array_pop($spans);
        }
        return [$spans, false];
    }

    private function atLineStartOrAfterSpace(): bool
    {
        $last = $this->spans === [] ? null : $this->spans[count($this->spans) - 1];
        return $last === null || $last->lineBreak || str_ends_with($last->text, ' ');
    }

    private function trimEnd(): void
    {
        $last = array_pop($this->spans);
        if ($last === null) {
            return;
        }
        if ($last->lineBreak || !str_ends_with($last->text, ' ')) {
            $this->spans[] = $last;
            return;
        }
        $text = substr($last->text, 0, -1);
        if ($text !== '') {
            $this->spans[] = new Span($text, $last->style, $last->href);
        }
    }
}
