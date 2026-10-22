<?php

declare(strict_types=1);

namespace Papercut\Markup;

use Papercut\Backend\ImageFormat;
use Papercut\Decorator\Blockquote;
use Papercut\Export\FidelityLevel;
use Papercut\Image;
use Papercut\Link;
use Papercut\ListItem;
use Papercut\ListNode;
use Papercut\Node;
use Papercut\Paint\Link as LinkPolicy;
use Papercut\Span;
use Papercut\Style\ListMarker;
use Papercut\Style\Style;
use Papercut\Styled;
use Papercut\Text;
use Papercut\Value\Color;
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
    private const array BLOCKS = ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ol', 'ul', 'li', 'figure', 'blockquote', 'img', 'iframe', 'video', 'audio'];

    /**
     * HTML block-level elements without a mapping: they end the paragraph around
     * them and their content becomes paragraphs of its own, so words never glue.
     */
    private const array UNKNOWN_BLOCKS = [
        'address', 'article', 'aside', 'dd', 'details', 'dialog', 'dl', 'dt', 'fieldset', 'footer',
        'form', 'header', 'hgroup', 'hr', 'main', 'nav', 'pre', 'section', 'summary', 'table', 'caption', 'thead',
        'tbody', 'tfoot', 'tr', 'td', 'th', 'center',
    ];

    private const string NBSP = "\u{00A0}";

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

    /** How many `pre` elements enclose the current node; inside one, newlines are line breaks. */
    private int $pre = 0;

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
                    $this->run->break();
                }
                $this->run->text($line, $style, $href);
            }
        } elseif ($node instanceof \Dom\Text) {
            $this->run->text($node->data, $style, $href);
        }
    }

    private function element(\Dom\Element $el, Style $style, ?string $href, ?string $preset): void
    {
        $tag = $el->localName;
        $css = $tag === 'br' || in_array($tag, self::REMOVED, true)
            ? null
            : $this->css($el, !in_array($tag, self::BLOCKS, true) && !in_array($tag, self::UNKNOWN_BLOCKS, true), $tag === 'ol' || $tag === 'ul');
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
                $this->run->break();
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
        if ($add !== null) {
            $this->children($el, $style->merge($add)->merge($own), $href, $preset);
            return;
        }

        if (in_array($tag, self::REMOVED, true)) {
            $this->note(FidelityLevel::Approximated, 'markup <' . $tag . '> (removed)');
            return;
        }
        $this->noteOnce(FidelityLevel::Approximated, 'markup <' . $tag . '> (tag removed, content kept)');
        if (!in_array($tag, self::UNKNOWN_BLOCKS, true)) {
            $this->children($el, $style->merge($own), $href, $preset);
            return;
        }
        $this->pre += $tag === 'pre' ? 1 : 0;
        try {
            $this->styled($own, $preset, fn () => $this->block($el, $style, $href, $preset, 'p'));
        } finally {
            $this->pre -= $tag === 'pre' ? 1 : 0;
        }
    }

    /**
     * The element's `style` attribute in StyleMode::Original, its dropped
     * declarations and any float noted; null in theme mode or without one.
     * $inline: a span-level element, which takes text properties only.
     *
     * @return array{style: Style, dropped: list<string>, float: bool, marker: ?ListMarker}|null
     */
    private function css(\Dom\Element $el, bool $inline, bool $list): ?array
    {
        $declarations = $this->options->styles === StyleMode::Original ? $el->getAttribute('style') : null;
        if ($declarations === null) {
            return null;
        }
        $css = InlineCss::parse($declarations, $inline, $list);
        if ($css['float']) {
            $this->note(FidelityLevel::Approximated, 'markup float (rendered as a block)');
        }
        foreach ($css['dropped'] as $declaration) {
            $this->note(FidelityLevel::Dropped, 'markup CSS "' . $declaration . '"');
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
            $text = $block instanceof Text && $block->preset() === null;
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

    /** The image bytes for $src from the app's resolver, or null when there are none Papercut can read (or no src). */
    private function resolve(string $src): ?string
    {
        $resolver = $this->options->images;
        if ($resolver === null || trim($src, " \t\n\r\f") === '') {
            return null;
        }
        try {
            $bytes = $resolver($src);
        } catch (\Throwable) {
            return null;
        }
        return is_string($bytes) && ImageFormat::sniff($bytes) !== null ? $bytes : null;
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
        return $preset === null ? $text : $text->withPreset($preset);
    }

    /** Records a note at the current element; the path is joined only here, so deep input stays linear in memory. */
    private function note(FidelityLevel $level, string $feature): void
    {
        $where = implode(' > ', $this->path);
        $key = $feature . "\0" . $where;
        if (!isset($this->noted[$key])) {
            $this->noted[$key] = true;
            $this->notes[] = new Note($level, $feature, $where);
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
        return $n > 0 ? $n : null;
    }
}
