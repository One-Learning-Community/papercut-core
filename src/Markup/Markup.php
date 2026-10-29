<?php

declare(strict_types=1);

namespace Papercut\Markup;

use Papercut\Component;
use Papercut\Export\FidelityLevel;
use Papercut\Image;
use Papercut\ListItem;
use Papercut\ListNode;
use Papercut\Node;
use Papercut\Span;
use Papercut\Style\StyleName;
use Papercut\Table;
use Papercut\Text;
use Papercut\View;

/** Converts plain text and markup into Papercut nodes. */
final class Markup
{
    /**
     * Nothing becomes an empty View, one paragraph a preset-less Text, several a View of `p` Texts.
     * $options is accepted for symmetry with html() and ignored: plain text has no styles, images or blank-paragraph markup.
     */
    public static function text(?string $text, MarkupOptions $options = new MarkupOptions()): View|Text
    {
        return PlainText::convert($text);
    }

    /** Converts an HTML fragment; the result has the same shape as text(), with conversion notes on the returned node. */
    public static function html(?string $html, MarkupOptions $options = new MarkupOptions()): View|Text
    {
        return HtmlConverter::convert($html, $options);
    }

    /**
     * Joins a converted result's text into one Text that can sit inside a line.
     * Spans and notes are kept; Text-level styles of the parts (a paragraph's
     * own style, say) are not, as with Text::append().
     */
    public static function flatten(View|Text $converted): Text
    {
        if ($converted instanceof Text) {
            return !StyleName::leads($converted->styleName(), 'pre')
                ? $converted
                : Text::spans(...self::named($converted))->apply($converted->style())->withNotes(...$converted->notes());
        }

        $blocks = [];
        $notes = [];
        self::collect($converted, $blocks, $notes);

        $spans = [];
        foreach ($blocks as $i => $block) {
            if ($i > 0) {
                $spans[] = Span::break();
            }
            array_push($spans, ...$block);
        }
        if (count($blocks) > 1) {
            $notes[] = new Note(FidelityLevel::Approximated, 'markup block content (flattened into a line)');
        }

        return Text::spans(...$spans)->withNotes(...$notes);
    }

    /**
     * The text's spans. The block style name `pre` moves onto its spans, so what lives in the
     * style, such as pre-wrap and the monospace font, survives when the block is flattened into a line.
     *
     * @return list<Span>
     */
    private static function named(Text $text): array
    {
        $name = $text->styleName();
        if (!StyleName::leads($name, 'pre')) {
            return $text->spanList();
        }
        return array_map(static fn (Span $s): Span => $s->lineBreak || $s->styleName !== null ? $s : new Span($s->text, $s->style, $s->href, false, $name), $text->spanList());
    }

    /**
     * @param list<list<Span>> $blocks
     * @param list<Note> $notes
     */
    private static function collect(Node|Component $node, array &$blocks, array &$notes): void
    {
        if ($node instanceof Text) {
            $blocks[] = self::named($node);
            array_push($notes, ...$node->notes());
        } elseif ($node instanceof View || $node instanceof ListItem) {
            if ($node instanceof View) {
                array_push($notes, ...$node->notes());
            }
            foreach ($node->children() as $child) {
                self::collect($child, $blocks, $notes);
            }
        } elseif ($node instanceof ListNode) {
            foreach ($node->items() as $item) {
                self::collect($item, $blocks, $notes);
            }
        } elseif ($node instanceof Table) {
            self::collectTable($node, $blocks, $notes);
        } elseif ($node instanceof Image) {
            $notes[] = new Note(FidelityLevel::Dropped, 'markup <img> (removed when flattened into a line)');
        } else {
            $notes[] = new Note(FidelityLevel::Dropped, 'markup ' . ($node instanceof Node ? $node->debugName() : 'component') . ' (removed when flattened into a line)');
        }
    }

    /**
     * A table is one block: a row's cells joined by spaces, rows by line breaks.
     *
     * @param list<list<Span>> $blocks
     * @param list<Note> $notes
     */
    private static function collectTable(Table $table, array &$blocks, array &$notes): void
    {
        $notes[] = new Note(FidelityLevel::Approximated, 'markup <table> (flattened into a line)');
        $spans = [];
        $first = true;
        foreach ([...$table->headerRows(), ...$table->bodyRows()] as $row) {
            $line = [];
            foreach ($row->cells() as $cell) {
                $parts = [];
                foreach ($cell->children() as $child) {
                    self::collect($child, $parts, $notes);
                }
                foreach ($parts as $part) {
                    if ($line !== []) {
                        $line[] = Span::text(' ');
                    }
                    array_push($line, ...$part);
                }
            }
            if ($line === []) {
                continue;
            }
            if (!$first) {
                $spans[] = Span::break();
            }
            $first = false;
            array_push($spans, ...$line);
        }
        if ($spans !== []) {
            $blocks[] = $spans;
        }
    }
}
