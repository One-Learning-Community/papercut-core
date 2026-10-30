<?php

declare(strict_types=1);

namespace Papercut\Markup;

use Papercut\Backend\ImageFormat;
use Papercut\Code\CodeHighlighting;
use Papercut\Decorator\{Blockquote, CodeBlock};
use Papercut\Export\FidelityLevel;
use Papercut\Image;
use Papercut\Layout\TableGrid;
use Papercut\Link;
use Papercut\ListItem;
use Papercut\ListNode;
use Papercut\Node;
use Papercut\Paint\Link as LinkPolicy;
use Papercut\Span;
use Papercut\Style\ListMarker;
use Papercut\Style\Style;
use Papercut\Style\StyleName;
use Papercut\Styled;
use Papercut\Table;
use Papercut\TableCell;
use Papercut\TableRow;
use Papercut\Text;
use Papercut\Value\Auto;
use Papercut\Value\Color;
use Papercut\Value\Length;
use Papercut\Value\Percent;
use Papercut\View;

use function Papercut\px;

/**
 * Converts an HTML fragment into Papercut nodes by walking the parsed DOM.
 * Block elements end the current inline run and start their own; inline
 * elements add to the style passed down to their text. Lists, list items and
 * figures convert their content in a frame of their own (see collect()). The
 * preset passed down is the one the current inline run flushes under; null,
 * inside a list item, means a bare Text. Note `where` paths name each element
 * with its index among its element siblings, root excluded. In
 * StyleMode::Original an element's `style` attribute applies on top (see css()).
 *
 * @internal
 */
final class HtmlConverter
{
    /** Tags dropped together with their content. */
    private const array REMOVED = ['script', 'style', 'template', 'head', 'noscript', 'object', 'embed'];

    private const array HEADINGS = ['h1' => 'h1', 'h2' => 'h2', 'h3' => 'h3', 'h4' => 'h4', 'h5' => 'h4', 'h6' => 'h4'];

    /** Elements that produce nodes of their own; any other element's `style` adds to its text style. */
    private const array BLOCKS = ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ol', 'ul', 'li', 'figure', 'blockquote', 'img', 'iframe', 'video', 'audio', 'hr'];

    /**
     * HTML block-level elements without a mapping: they end the paragraph around
     * them and their content becomes paragraphs of its own, so words never glue.
     */
    private const array UNKNOWN_BLOCKS = [
        'address', 'article', 'aside', 'dd', 'details', 'dialog', 'dl', 'dt', 'fieldset', 'footer',
        'form', 'header', 'hgroup', 'main', 'nav', 'pre', 'section', 'summary', 'table', 'caption', 'thead',
        'tbody', 'tfoot', 'tr', 'td', 'th', 'center',
    ];

    private const string NBSP = "\u{00A0}";

    /** The largest colspan or rowspan kept, as in browsers. */
    private const int MAX_SPAN = 1000;

    /** Elements nested deeper than this contribute their text only. */
    private const int MAX_DEPTH = 256;

    private InlineRun $run;

    /** @var list<array{Node, bool}> the current frame's blocks, each with whether it is a blank line */
    private array $blocks = [];

    /** @var list<ListItem>|null the items of the list whose frame is current; null outside a list */
    private ?array $items = null;

    /** @var list<Note> */
    private array $notes = [];

    /** @var array<string, true> */
    private array $noted = [];

    /** @var list<string> `tag[index]` segments from the root to the current element */
    private array $path = [];

    private bool $flattened = false;

    /** How many table cells enclose the current node; a table met inside one is flattened. */
    private int $cells = 0;

    /** How many flattened nested tables enclose the current node; their parts make no notes of their own. */
    private int $flat = 0;
    /** @var \WeakMap<TableCell, Length|Percent> the column width each cell of the table in hand asks for */
    private \WeakMap $widths;

    /** @var array<string, true>|null the features noted so far for the table in hand (once per table, not per cell); null outside a table */
    private ?array $partNoted = null;

    /** How many `pre` elements enclose the current node; inside one, spaces and tabs are kept and newlines are line breaks. */
    private int $pre = 0;

    /** How many code-like elements (code, kbd, samp, tt) outside a `pre` enclose the current node; their text takes the `code` style. */
    private int $code = 0;

    /** @var array<string, string|null> the resolver's answer per trimmed src in this conversion, nulls included */
    private array $resolved = [];

    private function __construct(private readonly MarkupOptions $options)
    {
        $this->run = new InlineRun();
    }

    public static function convert(?string $html, MarkupOptions $options): View|Text
    {
        $html = mb_scrub($html ?? '', 'UTF-8');
        if (trim($html) === '') {
            return View::make();
        }

        return new self($options)->run($html);
    }

    private function run(string $html): View|Text
    {
        $document = \Dom\HTMLDocument::createFromString(
            '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html,
            LIBXML_NOERROR,
        );
        $container = $document->body;
        if ($container !== null) {
            $this->children($container, new Style(), null, 'p');
        }
        $this->flush('p');

        return $this->result();
    }

    private function children(\Dom\Node $parent, Style $style, ?string $href, ?string $preset): void
    {
        $index = 0;
        foreach ($parent->childNodes as $node) {
            $this->child($node, $index, $style, $href, $preset);
        }
    }

    /** Converts one child node; $index counts the element siblings seen so far. */
    private function child(\Dom\Node $node, int &$index, Style $style, ?string $href, ?string $preset): void
    {
        if ($node instanceof \Dom\Element) {
            $this->path[] = $node->localName . '[' . $index++ . ']';
            if (count($this->path) > self::MAX_DEPTH) {
                if (!$this->flattened) {
                    $this->flattened = true;
                    $where = implode(' > ', [...array_slice($this->path, 0, 3), '…', ...array_slice($this->path, -3)]);
                    $this->notes[] = new Note(FidelityLevel::Approximated, 'markup nesting deeper than ' . self::MAX_DEPTH . ' levels (flattened)', $where);
                }
                $this->textOnly($node, $style, $href);
            } else {
                $this->element($node, $style, $href, $preset);
            }
            array_pop($this->path);
        } elseif ($node instanceof \Dom\Text && $this->pre > 0) {
            foreach (preg_split('/\r\n|\n|\r/', $node->data) ?: [] as $i => $line) {
                if ($i > 0) {
                    $this->run->break(true);
                }
                // Inside a pre the code span style is never applied (a <code> around a <pre> included).
                $this->run->text($line, $style, $href, true, null);
            }
        } elseif ($node instanceof \Dom\Text) {
            $this->run->text($node->data, $style, $href, false, $this->code > 0 && $this->pre === 0 ? 'code' : null);
        }
    }

    private function element(\Dom\Element $el, Style $style, ?string $href, ?string $preset): void
    {
        $tag = $el->localName;
        $css = $tag === 'br' || in_array($tag, self::REMOVED, true)
            ? null
            : $this->css($el, !in_array($tag, self::BLOCKS, true) && !in_array($tag, self::UNKNOWN_BLOCKS, true), $tag === 'ol' || $tag === 'ul', match ($tag) { 'table' => 'table', 'hr' => 'rule', default => null });
        $own = $css['style'] ?? new Style();
        switch ($tag) {
            case 'p':
            case 'div':
                $this->styled($own, $preset, fn () => $this->block($el, $style, $href, $preset, 'p'));
                return;
            case 'h1':
            case 'h2':
            case 'h3':
            case 'h4':
            case 'h5':
            case 'h6':
                $this->styled($own, $preset, fn () => $this->block($el, $style, $href, $preset, self::HEADINGS[$tag]));
                return;
            case 'br':
                $this->run->break($this->pre > 0);
                return;
            case 'pre':
                $highlighting = $this->options->code;
                if ($highlighting !== false && ($language = $this->codeLanguage($el)) !== null) {
                    $this->styled($own, $preset, fn () => $this->codeBlock($el, $language, $highlighting, $preset));
                    return;
                }
                $this->pre++;
                try {
                    $this->styled($own, $preset, fn () => $this->block($el, $style, $href, $preset, 'pre'));
                } finally {
                    $this->pre--;
                }
                return;
            case 'a':
                $this->children($el, $style->merge($own), $this->link($el, $href), $preset);
                return;
            case 'ol':
            case 'ul':
                $this->styled($own, $preset, fn () => $this->list($el, $style, $href, $preset, $css['marker'] ?? null));
                return;
            case 'li':
                if ($this->items === null) {
                    $this->styled($own, $preset, fn () => $this->block($el, $style, $href, $preset, 'p'));
                } else {
                    $this->item($el, $style, $href, $own);
                }
                return;
            case 'figure':
                $this->styled($own, $preset, fn () => $this->figure($el, $style, $href, $preset));
                return;
            case 'table':
                if ($this->cells === 0) {
                    $width = $this->declaredWidth($el, $own);
                    $this->table($el, $style, $href, $preset, $width, self::withoutWidth($own));
                    return;
                }
                $this->note(FidelityLevel::Approximated, 'markup nested <table> (flattened)');
                $this->flat++;
                try {
                    $this->styled($own, $preset, fn () => $this->block($el, $style, $href, $preset, 'p'));
                } finally {
                    $this->flat--;
                }
                return;
            case 'hr':
                $this->flush($preset);
                $rule = View::make()->apply('markup.hr');
                $this->blocks[] = [$own->isEmpty() ? $rule : $rule->apply($own), false];
                return;
            case 'blockquote':
                $this->styled($own, $preset, fn () => $this->blockquote($el, $style, $href, $preset));
                return;
            case 'img':
                $this->image($el, $style, $href, $preset, $own);
                return;
            case 'iframe':
            case 'video':
            case 'audio':
                $this->styled($own, $preset, fn () => $this->embed($el, $style, $href, $preset));
                return;
        }

        $add = match ($tag) {
            'b', 'strong' => new Style(fontWeight: 700),
            'i', 'em' => new Style(italic: true),
            'u' => new Style(underline: true),
            's', 'strike', 'del' => new Style(strike: true),
            'span' => new Style(),
            'mark' => new Style(background: Color::named('yellow-200')),
            default => null,
        };
        if (in_array($tag, ['code', 'kbd', 'samp', 'tt'], true)) {
            $named = $this->pre === 0;
            $this->code += $named ? 1 : 0;
            try {
                $this->children($el, $style->merge($own), $href, $preset);
            } finally {
                $this->code -= $named ? 1 : 0;
            }
            return;
        }
        if ($add !== null) {
            $this->children($el, $style->merge($add)->merge($own), $href, $preset);
            return;
        }

        if (in_array($tag, self::REMOVED, true)) {
            $this->note(FidelityLevel::Approximated, 'markup <' . $tag . '> (removed)');
            return;
        }
        if ($this->flat === 0) {
            $this->noteOnce(FidelityLevel::Approximated, 'markup <' . $tag . '> (tag removed, content kept)');
        }
        if (!in_array($tag, self::UNKNOWN_BLOCKS, true)) {
            $this->children($el, $style->merge($own), $href, $preset);
            return;
        }
        $this->styled($own, $preset, fn () => $this->block($el, $style, $href, $preset, 'p'));
    }

    /**
     * The element's `style` attribute in StyleMode::Original, its dropped
     * declarations and any float noted; null in theme mode or without one.
     * $inline: a span-level element, which takes text properties only.
     * $part: 'table' or 'cell' take a width only (other sizes would break the
     * grid; a cell also takes no margin); a cell's notes are made once per
     * table; 'rule' (an hr) takes its colours as the border colour.
     *
     * @return array{style: Style, dropped: list<string>, float: bool, marker: ?ListMarker}|null
     */
    private function css(\Dom\Element $el, bool $inline, bool $list, ?string $part = null): ?array
    {
        $declarations = $this->options->styles === StyleMode::Original ? $el->getAttribute('style') : null;
        if ($declarations === null) {
            return null;
        }
        // inside a table every note is made once per table, wherever the declaration comes up
        $each = $part === 'cell' || ($part !== 'table' && $this->partNoted !== null) ? $this->notePart(...) : $this->note(...);
        if ($part === 'table' || $part === 'cell') {
            $sizes = ['height', 'min-width', 'max-width', 'min-height', 'max-height'];
            if ($part === 'cell') {
                array_push($sizes, 'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left');
            }
            $kept = [];
            foreach (explode(';', $declarations) as $declaration) {
                $name = strtolower(trim(explode(':', $declaration, 2)[0]));
                if (in_array($name, $sizes, true)) {
                    $each(FidelityLevel::Dropped, 'markup CSS "' . trim($declaration) . '"');
                } else {
                    $kept[] = $declaration;
                }
            }
            $declarations = implode(';', $kept);
        }
        $css = InlineCss::parse($declarations, $inline, $list, $part === 'rule');
        if ($css['float']) {
            $each(FidelityLevel::Approximated, 'markup float (rendered as a block)');
        }
        foreach ($css['dropped'] as $declaration) {
            $each(FidelityLevel::Dropped, 'markup CSS "' . $declaration . '"');
        }
        return $css;
    }

    /**
     * Runs a block element's conversion and applies $own to what it added to
     * the current frame: the node itself, or a View around them when it made several.
     *
     * @param \Closure(): void $convert
     */
    private function styled(Style $own, ?string $outer, \Closure $convert): void
    {
        if ($own->isEmpty()) {
            $convert();
            return;
        }
        $this->flush($outer);
        $start = count($this->blocks);
        $convert();
        $made = array_splice($this->blocks, $start);
        if ($made !== []) {
            $blank = array_filter($made, static fn (array $b): bool => !$b[1]) === [];
            $this->blocks[] = [self::dressed(array_map(static fn (array $b): Node => $b[0], $made), $own), $blank];
        }
    }

    /**
     * One node carrying $own: the only node given, or a View of them all.
     *
     * @param non-empty-list<Node> $nodes
     */
    private static function dressed(array $nodes, Style $own): Node
    {
        $node = count($nodes) === 1 ? $nodes[0] : View::make(...$nodes);
        return $node instanceof Styled ? $node->apply($own) : View::make($node)->apply($own);
    }

    /** Ends the enclosing paragraph, converts the block's content under its own preset, and ends it too. */
    private function block(\Dom\Element $el, Style $style, ?string $href, ?string $outer, string $preset): void
    {
        $this->flush($outer);
        $this->children($el, $style, $href, $preset);
        $this->flush($preset);
    }

    /**
     * Converts content in a fresh frame (blocks, inline run, no enclosing list),
     * ends its last inline run under $preset, and returns its blocks, blank lines
     * trimmed from both ends; the enclosing frame is restored afterwards.
     *
     * @param \Closure(): void $fill
     * @return list<Node>
     */
    private function collect(?string $preset, \Closure $fill): array
    {
        [$blocks, $run, $items] = [$this->blocks, $this->run, $this->items];
        [$this->blocks, $this->run, $this->items] = [[], new InlineRun(), null];
        try {
            $fill();
            $this->flush($preset);
            return self::trimmed($this->blocks);
        } finally {
            [$this->blocks, $this->run, $this->items] = [$blocks, $run, $items];
        }
    }

    private function list(\Dom\Element $el, Style $style, ?string $href, ?string $outer, ?ListMarker $css): void
    {
        $this->flush($outer);
        $items = [];
        $this->collect(null, function () use ($el, $style, $href, &$items): void {
            $this->items = [];
            $this->children($el, $style, $href, null);
            $this->looseItem();
            $items = $this->items ?? [];
        });
        if ($items === []) {
            return;
        }

        $ordered = $el->localName === 'ol';
        $list = $ordered ? ListNode::ordered(...$items) : ListNode::unordered(...$items);
        if ($ordered) {
            $start = self::integer($el->getAttribute('start'));
            if ($start !== null) {
                $list = $list->start($start);
            }
            $marker = ListMarker::fromHtmlType($el->getAttribute('type') ?? '');
            if ($marker !== null) {
                $list = $list->marker($marker);
            }
        }
        if ($css !== null) {
            $list = $list->marker($css);
        }
        $this->blocks[] = [$list, false];
    }

    /** Converts an `li` of the current list into an item, after any loose content before it. */
    private function item(\Dom\Element $el, Style $style, ?string $href, Style $own): void
    {
        $this->looseItem();
        $content = $this->collect(null, fn () => $this->children($el, $style, $href, null));
        $item = ListItem::make(...self::unwrapped($content));
        if (!$own->isEmpty()) {
            $item = $item->apply($own);
        }
        $value = self::integer($el->getAttribute('value'));
        $this->items[] = $value === null ? $item : $item->withValue($value);
    }

    /**
     * Places content met directly inside the current list, outside any `li`, as
     * browsers do: loose text starts an item of its own, and other blocks (a
     * nested list, say) join the item before them, or start one when there is none.
     */
    private function looseItem(): void
    {
        $this->flush(null);
        $content = self::trimmed($this->blocks);
        $this->blocks = [];
        $items = $this->items ?? [];
        $pending = [];
        foreach ($content as $block) {
            $text = $block instanceof Text && $block->preset() === null && $block->styleName() === null;
            if ($pending === [] && !$text && $items !== []) {
                $last = array_pop($items);
                $joined = ListItem::make(...[...$last->children(), $block]);
                $joined = $last->style()->isEmpty() ? $joined : $joined->apply($last->style());
                $value = $last->valueOverride();
                $items[] = $value === null ? $joined : $joined->withValue($value);
            } elseif ($text && $pending !== []) {
                $items[] = ListItem::make(...self::unwrapped($pending));
                $pending = [$block];
            } else {
                $pending[] = $block;
            }
        }
        if ($pending !== []) {
            $items[] = ListItem::make(...self::unwrapped($pending));
        }
        $this->items = $items;
    }

    /**
     * A table becomes a Table with an auto track per column, filling the width,
     * under the named style `markup.table`. `thead` rows (or, without one, the
     * leading all-`th` rows) are the repeated header; `tfoot` rows go after the
     * body. A `caption` and anything met outside the cells become blocks before
     * the table. A table with no cells is nothing.
     */
    private function table(\Dom\Element $el, Style $style, ?string $href, ?string $outer, Length|Percent|null $width, Style $own): void
    {
        $noted = $this->partNoted;
        $this->partNoted = [];
        try {
            $this->tableOf($el, $style, $href, $outer, $width, $own);
        } finally {
            $this->partNoted = $noted;
        }
    }

    private function tableOf(\Dom\Element $el, Style $style, ?string $href, ?string $outer, Length|Percent|null $width, Style $own): void
    {
        $this->widths = new \WeakMap();
        $this->flush($outer);
        $head = null;
        $groups = [];
        $foot = [];
        $before = $this->collect('p', function () use ($el, $style, $href, &$head, &$groups, &$foot): void {
            $index = 0;
            foreach ($el->childNodes as $node) {
                $tag = $node instanceof \Dom\Element ? $node->localName : '';
                if (!in_array($tag, ['caption', 'colgroup', 'col', 'thead', 'tbody', 'tfoot', 'tr'], true) || count($this->path) >= self::MAX_DEPTH) {
                    $this->child($node, $index, $style, $href, 'p');
                    continue;
                }
                /** @var \Dom\Element $node */
                $this->path[] = $tag . '[' . $index++ . ']';
                try {
                    switch ($tag) {
                        case 'caption':
                            $made = count($this->blocks);
                            $this->block($node, $style, $href, 'p', 'p');
                            if (count($this->blocks) > $made) {
                                // the caption stays with its table
                                [$last, $blank] = $this->blocks[count($this->blocks) - 1];
                                $this->blocks[count($this->blocks) - 1] = [($last instanceof Styled ? $last : View::make($last))->apply(new Style(keepWithNext: true)), $blank];
                            }
                            break;
                        case 'thead':
                            $rows = $this->sectionRows($node, $style, $href);
                            if ($head === null) {
                                $head = $rows;
                            } else {
                                $groups[] = $rows;
                            }
                            break;
                        case 'tbody':
                            $groups[] = $this->sectionRows($node, $style, $href);
                            break;
                        case 'tfoot':
                            $foot[] = $this->sectionRows($node, $style, $href);
                            break;
                        case 'tr':
                            $groups[] = $this->rowOf($node, $style, $href);
                            break;
                    }
                } finally {
                    array_pop($this->path);
                }
            }
        });
        foreach ($before as $node) {
            $this->blocks[] = [$node, false];
        }

        $body = [];
        foreach ([...$groups, ...$foot] as $group) {
            array_push($body, ...$this->spanned($group));
        }
        $header = [];
        if ($head !== null) {
            $header = $this->spanned($head);
        } else {
            $leading = 0;
            while ($leading < count($body) && self::allHeader($body[$leading])) {
                $leading++;
            }
            // A header cell must not span into the body: shorten the header until none does.
            do {
                $stable = true;
                foreach (array_slice($body, 0, $leading) as $r => $row) {
                    foreach ($row->cells() as $cell) {
                        if ($cell->rowsSpanned() > $leading - $r) {
                            $leading = $r;
                            $stable = false;
                            break 2;
                        }
                    }
                }
            } while (!$stable);
            if ($leading > 0 && $leading < count($body)) {
                $header = array_slice($body, 0, $leading);
                $body = array_slice($body, $leading);
            }
        }
        if ($header === [] && $body === []) {
            return;
        }

        $table = Table::make();
        foreach ($header as $row) {
            $table = $table->headerRow($row);
        }
        foreach ($body as $row) {
            $table = $table->bodyRow($row);
        }
        $grid = TableGrid::of($table);
        $tracks = array_fill(0, $grid->columns, Auto::instance());
        $set = [];
        foreach ($grid->placements as $placed) {
            foreach ($placed as $g) {
                if ($g->colspan === 1 && !isset($set[$g->col]) && isset($this->widths[$g->cell])) {
                    $set[$g->col] = true;
                    $tracks[$g->col] = $this->widths[$g->cell];
                }
            }
        }
        $tracks = $this->fitted(array_values($tracks));
        $table = $table->columns(...$tracks)->fillWidth()->fitColumns()->apply('markup.table');
        if (!$own->isEmpty()) {
            $table = $table->apply($own);
        }
        if ($width !== null) {
            // kept as written; never wider than whatever holds it
            $table = $table->width($width)->maxWidth(Percent::of(100));
        }
        $this->blocks[] = [$table, false];
    }

    /**
     * The tracks with Percent columns that together exceed 100% scaled down to 100% (noted). Length columns are
     * left as written: the table fits them to the width it is given when it is laid out.
     *
     * @param list<Length|Percent|Auto> $tracks
     * @return list<Length|Percent|Auto>
     */
    private function fitted(array $tracks): array
    {
        $percent = 0.0;
        foreach ($tracks as $track) {
            $percent += $track instanceof Percent ? $track->value : 0.0;
        }
        if ($percent <= 100.0) {
            return $tracks;
        }
        $this->note(FidelityLevel::Approximated, 'markup column widths (wider than the page; fitted)');
        $scale = 100.0 / $percent;
        return array_map(static fn (Length|Percent|Auto $t): Length|Percent|Auto => $t instanceof Percent ? Percent::of($t->value * $scale) : $t, $tracks);
    }

    /**
     * The width an element declares in StyleMode::Original: its parsed `style`
     * width, else its `width` attribute (`120`, `120px`, `25%`); null otherwise.
     */
    private function declaredWidth(\Dom\Element $el, Style $own): Length|Percent|null
    {
        if ($this->options->styles !== StyleMode::Original) {
            return null;
        }
        if ($own->width instanceof Length || $own->width instanceof Percent) {
            return $own->width;
        }
        $attribute = $el->getAttribute('width');
        if ($attribute !== null && preg_match('/^\s*(\d+(?:\.\d+)?)%\s*$/', $attribute, $m) === 1 && is_finite((float) $m[1]) && (float) $m[1] > 0) {
            return Percent::of((float) $m[1]);
        }
        $pixels = self::pixels($attribute);
        return $pixels === null ? null : px($pixels);
    }

    private static function withoutWidth(Style $style): Style
    {
        return $style->width === null ? $style : new Style(...array_merge(get_object_vars($style), ['width' => null]));
    }

    /**
     * The rows of a thead, tbody or tfoot, as one row group; anything but a
     * `tr` goes before the table.
     *
     * @return list<list<array{cell: TableCell, rowspan: int, path: list<string>, width: Length|Percent|null}>>
     */
    private function sectionRows(\Dom\Element $section, Style $style, ?string $href): array
    {
        $rows = [];
        $index = 0;
        foreach ($section->childNodes as $node) {
            if (!$node instanceof \Dom\Element || $node->localName !== 'tr' || count($this->path) >= self::MAX_DEPTH) {
                $this->child($node, $index, $style, $href, 'p');
                continue;
            }
            $this->path[] = 'tr[' . $index++ . ']';
            try {
                array_push($rows, ...$this->rowOf($node, $style, $href));
            } finally {
                array_pop($this->path);
            }
        }
        return $rows;
    }

    /**
     * A tr as a list of one row (none when it holds no cells), each cell with its
     * rowspan as written (0: to the end of its row group) and where it sits.
     *
     * @return list<list<array{cell: TableCell, rowspan: int, path: list<string>, width: Length|Percent|null}>>
     */
    private function rowOf(\Dom\Element $tr, Style $style, ?string $href): array
    {
        $cells = [];
        $index = 0;
        foreach ($tr->childNodes as $node) {
            $tag = $node instanceof \Dom\Element ? $node->localName : '';
            if (($tag !== 'td' && $tag !== 'th') || count($this->path) >= self::MAX_DEPTH) {
                $this->child($node, $index, $style, $href, 'p');
                continue;
            }
            /** @var \Dom\Element $node */
            $this->path[] = $tag . '[' . $index++ . ']';
            try {
                $cells[] = $this->cell($node, $style, $href);
            } finally {
                array_pop($this->path);
            }
        }
        return $cells === [] ? [] : [$cells];
    }

    /** @return array{cell: TableCell, rowspan: int, path: list<string>, width: Length|Percent|null} */
    private function cell(\Dom\Element $el, Style $style, ?string $href): array
    {
        $own = $this->css($el, false, false, 'cell')['style'] ?? new Style();
        $width = $this->declaredWidth($el, $own);
        $own = self::withoutWidth($own);
        $this->cells++;
        try {
            $content = self::unwrapped($this->collect('p', fn () => $this->children($el, $style, $href, 'p')));
        } finally {
            $this->cells--;
        }
        $header = $el->localName === 'th';
        $cell = ($header ? TableCell::th(...$content) : TableCell::make(...$content))->apply($header ? 'markup.th' : 'markup.td');
        if (!$own->isEmpty()) {
            $cell = $cell->apply($own);
        }

        $colspan = $this->span($el->getAttribute('colspan'), 'colspan');
        if ($colspan > 1) {
            $cell = $cell->colspan($colspan);
        }
        $rowspan = self::integer($el->getAttribute('rowspan')) ?? 1;
        $rowspan = $rowspan < 0 ? 1 : $rowspan;
        if ($rowspan > self::MAX_SPAN) {
            $rowspan = self::MAX_SPAN;
            $this->notePart(FidelityLevel::Approximated, 'markup rowspan (clamped to ' . self::MAX_SPAN . ')');
        }
        return ['cell' => $cell, 'rowspan' => $rowspan, 'path' => $this->path, 'width' => $colspan === 1 ? $width : null];
    }

    /** A colspan by the HTML rules: below 1 is 1, above MAX_SPAN is MAX_SPAN (noted). */
    private function span(?string $value, string $name): int
    {
        $n = self::integer($value) ?? 1;
        if ($n > self::MAX_SPAN) {
            $this->notePart(FidelityLevel::Approximated, 'markup ' . $name . ' (clamped to ' . self::MAX_SPAN . ')');
            return self::MAX_SPAN;
        }
        return max(1, $n);
    }

    /**
     * Turns a row group into rows, giving each cell its rowspan: 0 reaches the end
     * of the group, and a rowspan past it is cut there with a note.
     *
     * @param list<list<array{cell: TableCell, rowspan: int, path: list<string>, width: Length|Percent|null}>> $group
     * @return list<TableRow>
     */
    private function spanned(array $group): array
    {
        $rows = [];
        foreach ($group as $r => $cells) {
            $left = count($group) - $r;
            $made = [];
            foreach ($cells as ['cell' => $cell, 'rowspan' => $rowspan, 'path' => $path, 'width' => $width]) {
                $n = $rowspan === 0 ? $left : $rowspan;
                if ($n > $left) {
                    $n = $left;
                    $this->notePart(FidelityLevel::Approximated, 'markup rowspan (clamped to the table section)', implode(' > ', $path));
                }
                $cell = $n > 1 ? $cell->rowspan($n) : $cell;
                if ($width !== null) {
                    $this->widths[$cell] = $width;
                }
                $made[] = $cell;
            }
            $rows[] = TableRow::make(...$made);
        }
        return $rows;
    }

    private static function allHeader(TableRow $row): bool
    {
        foreach ($row->cells() as $cell) {
            if (!$cell->header()) {
                return false;
            }
        }
        return true;
    }

    /**
     * The language of a `pre` that is a code block: the first `language-x` or `lang-x` class word
     * on it or on its only element child `code`. Null when it is not one, or code blocks are off.
     */
    private function codeLanguage(\Dom\Element $el): ?string
    {
        if ($this->options->code === false) {
            return null;
        }
        $only = self::onlyCode($el);
        return self::languageOf($el) ?? ($only !== null ? self::languageOf($only) : null);
    }

    /** The lone `code` child; with $bare, only when every other child is whitespace-only text. */
    private static function onlyCode(\Dom\Element $el, bool $bare = false): ?\Dom\Element
    {
        $first = $el->firstElementChild;
        if ($el->childElementCount !== 1 || $first?->localName !== 'code') {
            return null;
        }
        if ($bare) {
            foreach ($el->childNodes as $child) {
                if ($child !== $first && !($child instanceof \Dom\Text && trim($child->data, " \t\n\r\f") === '')) {
                    return null;
                }
            }
        }
        return $first;
    }

    /**
     * Emits the `pre` as a CodeBlock over its text, `br` as a newline and one final newline dropped.
     * Markup other than span, br and code inside is flattened (the highlighter colours the code
     * afresh) and noted.
     */
    private function codeBlock(\Dom\Element $el, string $language, CodeHighlighting $options, ?string $outer): void
    {
        $this->flush($outer);
        $code = '';
        $flat = false;
        $walk = function (\Dom\Node $node) use (&$walk, &$code, &$flat): void {
            foreach ($node->childNodes as $child) {
                if ($child instanceof \Dom\Text) {
                    $code .= $child->data;
                } elseif ($child instanceof \Dom\Element) {
                    if ($child->localName === 'br') {
                        $code .= "\n";
                        continue;
                    }
                    $flat = $flat || !in_array($child->localName, ['span', 'code'], true);
                    $walk($child);
                }
            }
        };
        $walk(self::onlyCode($el, true) ?? $el);
        if ($flat) {
            $this->note(FidelityLevel::Approximated, 'markup inside a code block');
        }
        $this->blocks[] = [CodeBlock::make($code)->language($language)->highlighting($options)->render(), false];
    }

    private static function languageOf(\Dom\Element $el): ?string
    {
        foreach (preg_split('/[ \t\n\r\f]+/', $el->getAttribute('class') ?? '', -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (preg_match('/^(?:language|lang)-(.+)$/', $word, $m) === 1) {
                return $m[1];
            }
        }
        return null;
    }

    /**
     * A blockquote is a Blockquote decorator, emitted as its rendered View. A
     * `cite` or `footer` that is its last element child becomes the attribution;
     * with nothing in it, it is nothing.
     */
    private function blockquote(\Dom\Element $el, Style $style, ?string $href, ?string $outer): void
    {
        $this->flush($outer);
        $last = $el->lastElementChild;
        $cite = null;
        if ($last !== null && in_array($last->localName, ['cite', 'footer'], true)) {
            $cite = $last;
            for ($tail = $last->nextSibling; $tail !== null; $tail = $tail->nextSibling) {
                if ($tail instanceof \Dom\Text && trim($tail->data, " \t\n\r\f") !== '') {
                    $cite = null;
                    break;
                }
            }
        }
        $attribution = null;
        $content = $this->collect('p', function () use ($el, $style, $href, $cite, &$attribution): void {
            $index = 0;
            foreach ($el->childNodes as $node) {
                if ($node !== $cite) {
                    $this->child($node, $index, $style, $href, 'p');
                    continue;
                }
                $this->flush('p');
                $this->path[] = $cite->localName . '[' . $index++ . ']';
                $own = $this->css($cite, true, false)['style'] ?? new Style();
                $parts = $this->collect(null, fn () => $this->children($cite, $style->merge($own), $href, null));
                array_pop($this->path);
                $attribution = $parts === [] ? null : self::attribution($parts);
            }
        });
        if ($content === [] && $attribution === null) {
            return;
        }
        $quote = Blockquote::make(...$content);
        $this->blocks[] = [($attribution === null ? $quote : $quote->cite($attribution))->render(), false];
    }

    /**
     * The attribution for a Blockquote: a plain string when the text carries no
     * styling of its own, else the Text.
     *
     * @param non-empty-list<Node> $parts
     */
    private static function attribution(array $parts): string|Node
    {
        if (count($parts) === 1 && $parts[0] instanceof Text) {
            $plain = true;
            foreach ($parts[0]->spanList() as $span) {
                $plain = $plain && $span->style->isEmpty() && $span->href === null && !$span->lineBreak;
            }
            return $plain ? implode('', array_map(static fn (Span $s): string => $s->text, $parts[0]->spanList())) : $parts[0];
        }
        return View::make(...$parts);
    }

    /** A figure is a column of its content followed by its captions; with nothing in it, it is nothing. */
    private function figure(\Dom\Element $el, Style $style, ?string $href, ?string $outer): void
    {
        $this->flush($outer);
        $captions = [];
        $content = $this->collect('p', function () use ($el, $style, $href, &$captions): void {
            $index = 0;
            foreach ($el->childNodes as $node) {
                if ($node instanceof \Dom\Element && $node->localName === 'figcaption' && count($this->path) < self::MAX_DEPTH) {
                    $this->flush('p');
                    $this->path[] = 'figcaption[' . $index++ . ']';
                    $own = $this->css($node, false, false)['style'] ?? new Style();
                    $caption = $this->collect('p', fn () => $this->block($node, $style, $href, 'p', 'p'));
                    if ($caption !== [] && !$own->isEmpty()) {
                        $caption = [self::dressed($caption, $own)];
                    }
                    array_push($captions, ...$caption);
                    array_pop($this->path);
                } else {
                    $this->child($node, $index, $style, $href, 'p');
                }
            }
        });
        $content = [...$content, ...$captions];
        if ($content !== []) {
            $this->blocks[] = [View::make(...$content), false];
        }
    }

    /**
     * $own, the img's own `style`, applies over its width and height attributes.
     * The attributes only ever set a width, so the image keeps the aspect of its
     * bytes and still shrinks to fit an empty page (an explicit height would not).
     */
    private function image(\Dom\Element $el, Style $style, ?string $href, ?string $preset, Style $own): void
    {
        $src = $el->getAttribute('src') ?? '';
        $bytes = $this->resolve($src);
        $this->flush($preset);

        if ($bytes !== null) {
            $image = Image::bytes($bytes);
            $width = self::attributeWidth($el, $bytes);
            if ($width !== null) {
                $image = $image->width(px($width));
            }
            if (!$own->isEmpty()) {
                $image = $image->apply($own);
            }
            $this->blocks[] = [$href === null ? $image : Link::to($href, $image), false];
            return;
        }

        $alt = $el->getAttribute('alt') ?? '';
        if (trim($alt, " \t\n\r\f") === '') {
            $this->note(FidelityLevel::Dropped, 'markup <img> "' . self::shortened($src) . '" (missing)');
            return;
        }
        $this->run->text($alt, $style->merge(new Style(italic: true)), $href);
        $this->flush($preset);
        $this->note(FidelityLevel::Approximated, 'markup <img> "' . self::shortened($src) . '" (missing; shown as its alt text)');
    }

    /**
     * The width in CSS pixels the img's attributes ask for: its `width`, or with
     * only a `height`, the width that height gives at the bytes' intrinsic aspect.
     */
    private static function attributeWidth(\Dom\Element $el, string $bytes): ?float
    {
        $width = self::pixels($el->getAttribute('width'));
        $height = self::pixels($el->getAttribute('height'));
        if ($width !== null || $height === null) {
            return $width;
        }
        $size = @getimagesizefromstring($bytes);
        return $size !== false && $size[0] > 0 && $size[1] > 0 ? $height * $size[0] / $size[1] : null;
    }

    /** The image bytes for $src from the app's resolver (asked once per distinct trimmed src), or null when there are none Papercut can read (or no src). */
    private function resolve(string $src): ?string
    {
        $resolver = $this->options->images;
        $src = trim($src, " \t\n\r\f");
        if ($resolver === null || $src === '') {
            return null;
        }
        if (array_key_exists($src, $this->resolved)) {
            return $this->resolved[$src];
        }
        try {
            $bytes = $resolver($src);
        } catch (\Throwable) {
            $bytes = null;
        }

        return $this->resolved[$src] = is_string($bytes) && ImageFormat::sniff($bytes) !== null ? $bytes : null;
    }

    /** An iframe, video or audio becomes a paragraph linking to its `src`; its own content is not shown. */
    private function embed(\Dom\Element $el, Style $style, ?string $href, ?string $preset): void
    {
        $tag = $el->localName;
        $src = trim($el->getAttribute('src') ?? '', " \t\n\r\f");
        if ($src === '' || !LinkPolicy::allowed($src)) {
            $this->note(FidelityLevel::Dropped, 'markup <' . $tag . '> (no usable src; removed)');
            return;
        }
        $title = trim($el->getAttribute('title') ?? '', " \t\n\r\f");
        $this->flush($preset);
        $this->run->text($title === '' ? $src : $title, $style, $src);
        $this->flush($preset);
        $this->note(FidelityLevel::Approximated, 'markup <' . $tag . '> (shown as a link)');
    }

    /**
     * Adds the text under an element nested past MAX_DEPTH, without structure or
     * notes. Iterative, so neither memory nor the call stack grows with depth.
     */
    private function textOnly(\Dom\Element $root, Style $style, ?string $href): void
    {
        $node = $root;
        while ($node !== null) {
            if ($node instanceof \Dom\Text) {
                $this->run->text($node->data, $style, $href);
            }
            $first = $node instanceof \Dom\Element && !in_array($node->localName, self::REMOVED, true) ? $node->firstChild : null;
            if ($first !== null) {
                $node = $first;
                continue;
            }
            while ($node !== null && $node !== $root && $node->nextSibling === null) {
                $node = $node->parentNode;
            }
            $node = $node === null || $node === $root ? null : $node->nextSibling;
        }
    }

    private function link(\Dom\Element $el, ?string $href): ?string
    {
        $target = $el->getAttribute('href');
        if ($target === null) {
            return $href;
        }
        $target = trim($target, " \t\n\r\f");
        if (LinkPolicy::allowed($target)) {
            return $target;
        }
        $this->note(FidelityLevel::Dropped, 'markup link "' . self::shortened($target) . '" (unsupported link; link removed, content kept)');
        return $href;
    }

    /** Ends the current inline run as a paragraph under $preset (null: a bare Text). */
    private function flush(?string $preset): void
    {
        [$spans, $blank] = $this->run->take();
        if ($spans !== []) {
            $this->blocks[] = [self::paragraph($spans, $preset), false];
        } elseif ($blank && $this->options->blankParagraphs === BlankParagraphs::Keep) {
            $this->blocks[] = [self::paragraph([Span::text(self::NBSP)], $preset), true];
        }
    }

    /** @param list<Span> $spans */
    private static function paragraph(array $spans, ?string $preset): Text
    {
        $text = Text::spans(...$spans);
        return match ($preset) {
            null => $text,
            'pre' => $text->apply('pre'),
            default => $text->withPreset($preset),
        };
    }

    /** Records a note at the current element; the path is joined only here, so deep input stays linear in memory. */
    private function note(FidelityLevel $level, string $feature): void
    {
        $this->noteAt($level, $feature, implode(' > ', $this->path));
    }

    private function noteAt(FidelityLevel $level, string $feature, string $where): void
    {
        $key = $feature . "\0" . $where;
        if (!isset($this->noted[$key])) {
            $this->noted[$key] = true;
            $this->notes[] = new Note($level, $feature, $where);
        }
    }

    /**
     * Records a note on a table part (a cell, a span) once per feature per table, at the first
     * place it comes up: a pasted table would otherwise repeat it for every row.
     */
    private function notePart(FidelityLevel $level, string $feature, ?string $where = null): void
    {
        if ($this->partNoted === null) {
            $where === null ? $this->note($level, $feature) : $this->noteAt($level, $feature, $where);
            return;
        }
        if (!isset($this->partNoted[$feature])) {
            $this->partNoted[$feature] = true;
            $this->notes[] = new Note($level, $feature, $where ?? implode(' > ', $this->path));
        }
    }

    /** Records a note the first time $feature comes up in this conversion, wherever that is. */
    private function noteOnce(FidelityLevel $level, string $feature): void
    {
        if (!isset($this->noted[$feature])) {
            $this->noted[$feature] = true;
            $this->notes[] = new Note($level, $feature, implode(' > ', $this->path));
        }
    }

    private function result(): View|Text
    {
        $blocks = self::trimmed($this->blocks);
        if (count($blocks) === 1 && $blocks[0] instanceof Text && $blocks[0]->preset() === 'p') {
            return self::bare($blocks[0])->withNotes(...$this->notes);
        }
        if (count($blocks) === 1 && $blocks[0] instanceof Text && StyleName::leads($blocks[0]->styleName(), 'pre')) {
            return $blocks[0]->withNotes(...$this->notes);
        }
        return View::make(...$blocks)->withNotes(...$this->notes);
    }

    /**
     * The blocks without the blank lines at either end.
     *
     * @param list<array{Node, bool}> $blocks
     * @return list<Node>
     */
    private static function trimmed(array $blocks): array
    {
        while ($blocks !== [] && $blocks[0][1]) {
            array_shift($blocks);
        }
        while ($blocks !== [] && $blocks[count($blocks) - 1][1]) {
            array_pop($blocks);
        }
        return array_map(static fn (array $b): Node => $b[0], $blocks);
    }

    /**
     * A list item's content, a lone `p` paragraph made bare as at the top level.
     *
     * @param list<Node> $blocks
     * @return list<Node>
     */
    private static function unwrapped(array $blocks): array
    {
        if (count($blocks) === 1 && $blocks[0] instanceof Text && $blocks[0]->preset() === 'p') {
            return [self::bare($blocks[0])];
        }
        return $blocks;
    }

    /** A `p` paragraph without its preset, keeping any style the markup gave it. */
    private static function bare(Text $text): Text
    {
        $bare = Text::spans(...$text->spanList());
        return $text->style()->isEmpty() ? $bare : $bare->apply($text->style());
    }

    /** A src or href as shown in a note: at most 100 characters, so a data: URI does not fill the report. */
    private static function shortened(string $src): string
    {
        return mb_strlen($src) > 100 ? mb_substr($src, 0, 100) . '…' : $src;
    }

    /**
     * An HTML integer attribute (`start`, `value`) by the HTML parsing rules
     * (leading whitespace, optional sign, digits; anything after is ignored),
     * clamped to the 32-bit range as browsers do; null when absent or without digits.
     */
    private static function integer(?string $value): ?int
    {
        if ($value === null || preg_match('/^[ \t\n\r\f]*([+-]?)0*(\d+)/', $value, $m) !== 1) {
            return null;
        }
        $n = strlen($m[2]) > 10 ? PHP_INT_MAX : (int) $m[2];
        $n = $m[1] === '-' ? -$n : $n;
        return max(-2147483648, min(2147483647, $n));
    }

    /** A `width`/`height` attribute in CSS pixels (`221` or `221px`), or null when absent or not a positive length. */
    private static function pixels(?string $value): ?float
    {
        if ($value === null || preg_match('/^\s*(\d+(?:\.\d+)?)(?:px)?\s*$/i', $value, $m) !== 1) {
            return null;
        }
        $n = (float) $m[1];
        return is_finite($n) && $n > 0 && is_finite($n * 0.75) ? $n : null;
    }
}
