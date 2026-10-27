# papercut/core

Component-based, page-aware PDF layout engine for modern PHP. Build documents from typed, immutable
components with flexbox-style layout and page-aware pagination; a pure-PHP engine turns the tree into a PDF.
There is no browser, no HTML parser and no CSS cascade.

> **Status: v0.1, pre-release.** Not yet published to Packagist. Using Laravel? See
> [`papercut/laravel`](../laravel) for the Blade front end of this same engine.

## Requirements

PHP 8.4+, `ext-mbstring`, `ext-zlib`, `ext-dom`. The only Composer dependency is
[`dskripchenko/php-pdf`](https://github.com/dskripchenko/php-pdf) (MIT), used behind a backend adapter.

## Quick start

Every example below is a complete snippet that leaves a `Papercut\Document` in `$document` (and, where needed, a
`PdfOptions` in `$options`); the test suite runs each one (`tests/Feature/ReadmeTest.php`). Real code would end with `$document->toPdf()->save('out.pdf')`.

```php
use Papercut\{Column, Document, Page, Text};
use function Papercut\{mm, pt};

$document = Document::make(
    Page::a4()->margin(mm(20))->body(
        Column::make(
            Text::h1('Hello, Papercut'),
            Text::p('A column of text. Inter is bundled, so this embeds a real font.'),
        )->gap(pt(8)),
    ),
)->title('Hello');
```

Output: `$document->toPdf()` returns an `Output`: `->save($path)`, `->bytes()` (a string of PDF bytes) or `->stream()`
(writes to output, returns the byte count).

## Elements

| Element | Purpose |
|---|---|
| `Document::make(Page ...)` | Root. Metadata: `title`, `author`, `subject`, `keywords`, `lang`, `createdAt`. |
| `Page::a4()` (also `a3 a5 a6 letter legal`, `size($w, $h)`, and `named()` for those plus `a0 a1 a2 b4 b5 tabloid executive`) | A page. `landscape()`, `margin()`, `header()`, `footer()`, `background()`, `overlay()`, `body()`. `Page::template($pdfPage)` sizes a page from a [PDF template](#pdf-templates). |
| `Row::make(...)`, `Column::make(...)` | Flex containers (horizontal, vertical). |
| `Spacer::flex()`, `Spacer::size($length)` | Flexible or fixed gap. |
| `Text::make()`, `h1`-`h4`, `p`, `markup()`, `spans()` | Text, with inline spans. |
| `Image::file()`, `bytes()` | PNG and JPEG; WebP, GIF, BMP and AVIF with `ext-gd`. `fit()` modes. Images shrink to fit the page width, and an image taller than an empty page shrinks to fit it, unless its height is set explicitly. |
| `Svg::file()`, `fromString()` | An [SVG](#svg) drawn as vector paths, sized like an image. |
| `PdfPage::file()`, `bytes()` | A page of an existing [PDF](#pdf-templates), drawn as it is and sized like an image. |
| `Place::at($x, $y, ...)` | Content at a point on the page, for `Page::overlay()`. |
| `ListNode::ordered()`, `unordered()` | Numbered or bulleted [lists](#lists) of `ListItem`s. |
| `Markup::html()`, `text()`, `flatten()` | [HTML and plain text](#markup-input) converted to nodes. |
| `Link::to($uri, ...$children)` | A clickable region around children. |
| `Table::make()` | Page-aware table. |
| `PageNumber::make('Page {page} of {total}')` | Page number and page count. |
| `PageBreak::make()` | Forced break. |

Components are immutable: every method returns a modified copy.

## Units

Lengths are typed values created by helper functions: `pt()`, `px()`, `mm()`, `cm()`, `in()`. Also
`percent()` and `fr()` where a track or size accepts them. A bare number is never a length, so there is
no ambiguity about the unit. Strings are accepted where noted (`'1fr 20mm'`, `'slate-500'`).

```php
use Papercut\{Column, Document, Page, Row, Text};
use Papercut\Style\Justify;
use Papercut\Value\Edges;
use function Papercut\{mm, pt, percent};

$document = Document::make(
    Page::a4()->margin(mm(15))->body(
        Column::make(
            Row::make(
                Text::make('Left')->grow(1),
                Text::make('Right')->right(),
            )->justify(Justify::SpaceBetween)->gap(pt(12)),
            Text::make('Half width')->width(percent(50))->padding(Edges::of(pt(4), pt(8))),
            Text::make('Fixed 5 cm')->width(mm(50))->border(pt(1), 'slate-300'),
        )->gap(mm(4)),
    ),
);
```

## Styles

Style methods are available on every component and cascade to descendants where it makes sense (font, size,
colour, alignment). Layout: `width height minWidth maxWidth minHeight maxHeight padding margin border borderWidth
borderColor radius background opacity grow shrink basis alignSelf gap justify`. Text: `font size weight bold italic
color lineHeight letterSpacing left center right justify underline strike`. Pagination: `keepTogether keepWithNext
breakBefore breakAfter minLines`.

Colours are `Color` values or strings: hex (`'#336699'`) or the bundled Tailwind palette (`'slate-900'`,
`'indigo-500'`).

Named styles live in the `Theme`; apply one by name:

```php
use Papercut\{Column, Document, Page, Text};
use Papercut\Export\PdfOptions;
use Papercut\Style\{Style, Theme};
use Papercut\Value\Color;
use function Papercut\{mm, pt};

$theme = Theme::default()->withStyle('highlight', Style::make(color: Color::parse('indigo-600'), fontWeight: 700));

$document = Document::make(
    Page::a4()->margin(mm(20))->body(
        Column::make(
            Text::make('Plain'),
            Text::make('Named style')->apply('highlight'),
        )->gap(pt(6)),
    ),
);
$options = new PdfOptions(theme: $theme); // pass to toPdf()
```

Pass `PdfOptions` to `toPdf()`.

## Text and inline markup

`Text::make()` draws plain text. `Text::markup()` parses a small inline vocabulary (never HTML):
bold, italic, underline, strike, `<span>` with style attributes, `<a href>` and `<br>`. Unknown tags are errors.
For full control build spans yourself.

```php
use Papercut\{Column, Document, Page, Span, Text};
use Papercut\Style\Style;
use function Papercut\{mm, pt};

$document = Document::make(
    Page::a4()->margin(mm(20))->body(
        Column::make(
            Text::markup('Plain, <b>bold</b>, <i>italic</i> and <span color="indigo-600">coloured</span>.'),
            Text::spans(
                Span::text('Built from '),
                Span::text('spans', new Style(fontWeight: 700)),
                Span::break(),
                Span::text('after a line break.'),
            ),
        )->gap(pt(8)),
    ),
);
```

## Lists

`ListNode::ordered(...)` and `ListNode::unordered(...)` take `ListItem`s (strings and nodes are wrapped for you).
An item holds any block content, including another list. `start(int)` sets the first number, `marker(ListMarker)`
picks the style, and `ListItem::withValue(int)` sets one item's number.

Without a marker, the style follows the nesting depth (counting only lists of the same kind): ordered lists use
`1.`, `a.`, `i.` and then repeat; unordered lists use `•`, `◦`, `▪` and then repeat. Markers sit in a right-aligned
gutter sized to the list's widest marker (so `9.` and `10.` line up) and align with the baseline of the item's first
line. An item can split across pages; its marker stays with its first line. HTML export writes real `<ol>`/`<ul>`/`<li>`.

| `ListMarker` | Shows |
|---|---|
| `Decimal`, `LowerAlpha`, `UpperAlpha`, `LowerRoman`, `UpperRoman` | `1.` `a.` `A.` `i.` `I.` |
| `Disc`, `Circle`, `Square` | `•` `◦` `▪` |
| `None` | No marker (the gutter stays) |

```php
use Papercut\{Document, ListItem, ListNode, Page};
use Papercut\Style\ListMarker;
use function Papercut\mm;

$document = Document::make(
    Page::a4()->margin(mm(20))->body(
        ListNode::ordered(
            ListItem::make('Plan', ListNode::unordered('Scope', 'Budget')),
            'Build',
            ListItem::make('Ship')->withValue(10),
        )->start(3)->marker(ListMarker::UpperAlpha),
    ),
);
```

## Markup input

`Markup` turns text that came from elsewhere (a database column, an editor, an LLM prompt) into nodes. It is not a
browser: it maps a small, useful subset of HTML and reports the rest.

```php
use Papercut\{Column, Document, Page, Text};
use Papercut\Markup\{Markup, MarkupOptions, StyleMode};
use function Papercut\{mm, pt};

$slide = '<h2>Agenda</h2><p>Welcome to <b>Papercut</b>.</p><ol start="3"><li>Intro</li><li>Demo</li></ol>';

$document = Document::make(
    Page::a4()->margin(mm(20))->body(
        Column::make(
            Markup::html($slide, new MarkupOptions(styles: StyleMode::Theme)),
            Markup::text("Line one\nLine two\n\nA new paragraph."),
            Text::make('× ')->bold()->append(Markup::flatten(Markup::html('<i>label</i>'))),
        )->gap(pt(8)),
    ),
);
```

- `Markup::html(?string, MarkupOptions)` converts an HTML fragment (parsed with `ext-dom`; no network, no external entities).
- `Markup::text(?string, MarkupOptions)` decodes entities, turns newlines into line breaks and a blank line into a paragraph break. `<` is an ordinary character. It ignores its `MarkupOptions` (plain text has no styles, images or blank-paragraph markup); the parameter is there for symmetry with `html()`.
- `Markup::flatten($converted)` joins the result into one `Text` so it can sit inside a line. Block content is flattened with a report; images and other non-text nodes are removed with a `dropped` report. Like `Text::append(Text)`, it keeps each part's spans (with their styles) and notes, but not a part's own Text-level style, such as a paragraph's `style="…"` in `Original` mode.
- Both entry points return a `Text` (a single paragraph of inline content) or a `View`, and accept `null` and `''`, which produce nothing.

`MarkupOptions`: `styles` (`StyleMode::Theme` default or `Original`), `images` (a `Closure(string $src): ?string`
returning image bytes) and `blankParagraphs` (`BlankParagraphs::Drop` default, or `Keep`).

### What is converted

| HTML | Becomes |
|---|---|
| `p`, `div` | A paragraph |
| `h1`-`h4`; `h5`, `h6` | The theme's heading presets; `h5` and `h6` use `h4` |
| `b`/`strong`, `i`/`em`, `u`, `s`/`strike`/`del`, `span`, `br` | Inline spans |
| `a href` | A link around its content; other links are removed, content kept (see below) |
| `ol`, `ul`, `li` | A `ListNode`; `start` and `type` (`1 a A i I`) on `ol` only, `value` on `li` |
| `img` | An image through the resolver |
| `figure`, `figcaption` | A block; the caption becomes a paragraph below |
| `iframe`, `video`, `audio` | A paragraph linking to `src` (its `title` as text, else the URL) |
| other block-level tags (`blockquote`, `pre`, `section`, `table`/`tr`/`td`, `dl`/`dt`/`dd`, `hr`, …) | The tag is removed and its content kept as paragraphs of its own; `pre` keeps its line breaks |
| anything else, including `code`, `mark`, `sup` | The tag is removed and its content kept inline |

`script`, `style`, `template`, `head`, `noscript`, `object` and `embed` are removed with their content. Classes,
`id`, `data-*`, `target`, `rel`, blank paragraphs and zero-width characters are ignored without a report. Source
whitespace collapses as in a browser; `&nbsp;` is kept. Blank paragraphs (`<p></p>`, `<p><br></p>`,
`<p><i>&nbsp;</i></p>`) are dropped unless `BlankParagraphs::Keep`, which keeps those holding only `&nbsp;` or `<br>`
as one blank line each. A truly empty `<p></p>` is dropped in both modes.

### Images

Papercut never reads an `<img src>` itself. The `images` closure gets the raw decoded `src` and returns the image
bytes, or `null` for a missing image (a throw counts as missing). Bytes that are not a recognised image format
(PNG, JPEG, and with `ext-gd` WebP, GIF, BMP, AVIF; sniffed at conversion time) are treated as missing too. A missing
image shows its `alt` text in italics, or nothing without `alt`. `width` and `height` attributes apply in both style
modes (CSS pixels at 0.75pt) as a width only: with both, the `width` wins; with only a `height`, the width follows
from the image's own aspect ratio. The image keeps the aspect of its bytes, so a tall screenshot still shrinks to fit
an empty page. An `img` inside `a href` becomes a linked image.

```php
use Papercut\{Document, Page};
use Papercut\Markup\{Markup, MarkupOptions};
use function Papercut\mm;

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
$markup = new MarkupOptions(images: fn (string $src): ?string => $src === 'logo.png' ? $png : null);

$document = Document::make(
    Page::a4()->margin(mm(20))->body(
        Markup::html('<p><img src="logo.png" width="40" height="40"></p><p><img src="gone.png" alt="Missing logo"></p>', $markup),
    ),
);
```

### Style modes

- `Theme` (default): the HTML supplies structure only. `style="…"` attributes are ignored silently; `img width/height`, `ol start/type` and `li value` still apply.
- `Original`: each element's inline `style="…"` is applied on top of the theme.

In `Original` mode these properties are understood; `px` converts at 0.75pt.

| CSS | Applies to |
|---|---|
| `color`, `font-size`, `font-weight`, `font-style`, `text-decoration` (`underline`, `line-through`, `none`) | All elements, including spans |
| `background-color`, `text-align`, `margin`, `padding` | Blocks |
| `width`, `height`, `min-*`, `max-*` | Blocks and images |
| `list-style-type` | Lists |

Every other declaration, and any value Papercut's parsers refuse, is dropped and reported by declaration. A trailing
`!important` is ignored and units are case-insensitive (`12PX`). Known gap: CSS colour keywords such as `red` and
`hsl()` are not recognised yet.
`float: left|right` renders a normal block (reported); floats set through classes are invisible and not reported.

### What gets reported

Conversion notes travel with the converted nodes and reach `onWarning` and the fidelity report of every export
(once per export, with the node path). Strict mode throws only for the `dropped` ones, as everywhere else.

| Case | Level | Fails a strict export |
|---|---|---|
| `iframe`, `video`, `audio` shown as a link | approximated | no |
| `script`, `style`, `template`, `head`, `noscript`, `object`, `embed` removed with their content | approximated | no |
| Missing image shown as its alt text | approximated | no |
| Float rendered as a normal block | approximated | no |
| Unknown tag removed, content kept (once per tag name, at its first occurrence) | approximated | no |
| Block content flattened by `Markup::flatten()` | approximated | no |
| Nesting deeper than 256 levels flattened to text (one report) | approximated | no |
| Missing image with no alt | dropped | yes |
| `iframe`, `video`, `audio` with no usable `src`: removed | dropped | yes |
| Image or other non-text node removed by `Markup::flatten()` | dropped | yes |
| Unsupported link (another scheme, or a relative URL): link removed, content kept | dropped | yes |
| Unsupported inline CSS declaration | dropped | yes |

Only `http:`, `https:`, `mailto:` and `tel:` URLs and `#fragment` links survive; any other `href` (including a
relative one) is removed with its content kept, and the same rule decides an `iframe`/`video`/`audio` `src`.

## Tables

Tables take a column track list (`'3fr 25mm 20mm'`, `'1fr 1fr'`, ...), header rows, and body rows. A table breaks
between rows, never through one (unless a single row is taller than a page), and the header repeats on every page
the table crosses (`repeatHeader(false)` turns that off).

```php
use Papercut\{Document, Page, Table, TableRow, Text};
use function Papercut\{mm, pt};

$rows = [];
for ($i = 1; $i <= 120; $i++) {
    $rows[] = ["Item $i", $i * 3, number_format($i * 12.5, 2)];
}

$table = Table::make()
    ->columns('1fr 25mm 30mm')
    ->headerRow(TableRow::make(
        Text::make('Name')->bold(),
        Text::make('Qty')->bold()->right(),
        Text::make('Price')->bold()->right(),
    )->background('slate-100'))
    ->rows($rows, fn (array $r) => [$r[0], Text::make((string) $r[1])->right(), Text::make($r[2])->right()]);

$document = Document::make(Page::a4()->margin(mm(20))->body($table));
```

## Headers, footers and page numbers

`Page::header()`, `footer()` and `background()` repeat on every page the body fills.
`PageNumber::make('Page {page} of {total}')` fills in both numbers, even though `{total}` is only known after layout.

```php
use Papercut\{Column, Document, Page, PageNumber, Row, Spacer, Text};
use function Papercut\{mm, pt};

$body = [];
for ($i = 1; $i <= 80; $i++) {
    $body[] = Text::make("Paragraph $i. " . str_repeat('Lorem ipsum dolor sit amet. ', 6));
}

$document = Document::make(
    Page::a4()->margin(mm(20))
        ->header(Text::make('Quarterly report')->color('slate-500')->size(pt(8)))
        ->footer(Row::make(Spacer::flex(), PageNumber::make('Page {page} of {total}')->size(pt(8))))
        ->body(Column::make(...$body)->gap(pt(6))),
);
```

## Pagination controls

- `keepTogether()`: do not split this block across pages; move it whole to the next page.
- `keepWithNext()`: keep this block on the same page as its next sibling (headings use this by default).
- `breakBefore()` / `breakAfter()` and `PageBreak::make()`: force a new page.
- `minLines(n)`: minimum lines left at the bottom (and carried to the top) when a paragraph splits.

```php
use Papercut\{Column, Document, Page, PageBreak, Text};
use function Papercut\{mm, pt};

$document = Document::make(
    Page::a4()->margin(mm(20))->body(
        Column::make(
            Text::h2('Section one')->keepWithNext(),
            Text::make(str_repeat('Body text. ', 200))->minLines(3),
            PageBreak::make(),
            Text::h2('Section two')->breakBefore(false),
            Column::make(Text::make('A block'), Text::make('that stays whole'))->keepTogether(),
        )->gap(pt(8)),
    ),
);
```

## Bookmarks

Headings `h1` to `h4` become entries in the PDF's bookmarks panel, nested by level. Any component can add one with `bookmark($title, $level = null)`, and `bookmark(false)` takes a heading out. Headers, footers and backgrounds never add bookmarks. `new PdfOptions(bookmarks: false)` leaves the panel empty.

```php
use Papercut\{Column, Document, Page, Text};

$document = Document::make(Page::a4()->body(Column::make(
    Text::h1('Annual report'),
    Text::h2('A long heading that reads badly in a sidebar')->bookmark('Summary'),
    Column::make(Text::make('…'))->bookmark('Appendix', 1),
)));
```

## Encryption

`new PdfOptions(encryption: Encryption::password('secret'))` encrypts the PDF with AES-256 so it opens only with that password; readers may print and copy but not edit. `new Encryption($userPassword, $ownerPassword, print:, copy:, modify:, annotate:, assemble:)` sets the permissions. An empty user password lets anyone open the document while the permissions apply. Without an owner password a random one is generated, so the permissions cannot be lifted. Screen-reader access is always allowed. Signing and merging are left to php-pdf's `PdfSigner` and `PdfMerger`, which work on Papercut's output.

## QR codes

`QrCode::make($value, ErrorCorrection $level = Medium)` draws a QR code as vector squares in the text color. It is square: `width()` (or `height()`) sets the side, 25mm by default, quiet zone included (`quietZone(n)`, 4 modules by default). php-pdf's encoder holds up to 213 bytes at Medium; implement `Papercut\Barcode\QrEncoder` to use another, or pass a grid to `QrCode::fromModules()`. HTML exports draw it as an inline SVG.

```php
use Papercut\{Document, Page, QrCode};
use function Papercut\mm;

$document = Document::make(Page::a4()->body(QrCode::make('https://acme.test/pay/INV-142')->width(mm(30))));
```

With the optional `bacon/bacon-qr-code` package installed, you can style a code: `shape(QrShape::Rounded|Dots)`, `eyes(QrEyes::Dot|Pointy)`, `eyeColor($color)` and `gradient($from, $to, QrGradient $type = Diagonal)`. Papercut still encodes the modules, and Bacon draws them as an SVG. Without the package, styling throws `MissingPackage` with the `composer require` command. Dots and rounded modules are harder to scan, so they raise the error correction to `Quartile` (up to 151 bytes), re-encoding the text, unless it is already `High`; `errorCorrection()` won't take such a code lower. `new BaconQrEncoder()` (in `Papercut\Barcode\Bacon`) encodes up to 2,953 bytes when passed as the third argument to `make()`. HTML exports show a styled code as an SVG image.

```php
use Papercut\{Document, Page, QrCode};
use Papercut\Barcode\{QrEyes, QrShape};
use function Papercut\mm;

$qr = QrCode::make('https://acme.test/pay/INV-142')
    ->shape(QrShape::Dots)
    ->eyes(QrEyes::Dot)
    ->eyeColor('rose-600')
    ->gradient('indigo-600', 'cyan-700')
    ->width(mm(35));
$document = Document::make(Page::a4()->body($qr));
```

## SVG

`Svg::file($path)` and `Svg::fromString($markup)` draw an SVG as vector paths, so it stays sharp at any size. It is sized like an image: the SVG's `width` and `height` (or its `viewBox`, one unit to a pixel) give the natural size, setting one side keeps the aspect ratio, and it shrinks to fit the page width. With both sides set, the SVG's `preserveAspectRatio` places the drawing in the box, and anything outside the box is cut off. An SVG never splits across pages.

Paths (arcs included), basic shapes, groups with nested transforms and inherited fill and stroke, `<use>`, `<style>` rules, opacity, `fill-rule="evenodd"`, and linear and radial gradients on any shape are drawn. `<clipPath>`, `<mask>`, `<filter>`, `<pattern>`, `<image>`, `<marker>`, `<symbol>`, `<textPath>` and `<tspan>` are not drawn yet, and a warning names any the SVG uses. `<text>` is set in the document's embedded fonts: a `font-family` you registered is used, otherwise the SVG element's own family.

Untrusted SVG is safe to draw. Papercut never loads files, DTDs or URLs named in an SVG, refuses one that declares entities, removes scripts and event handlers, and refuses one over 10 MB or over a million units of drawing work, with each `<use>` counted (one per path point, 15 per circle or ellipse, 5 per other element; `ImageUnreadable`). HTML exports show it as an `<img>` with a `data:` URI, never inline, with the SVG's `<title>` as the alt text.

```php
use Papercut\{Document, Page, Svg};
use function Papercut\mm;

$ring = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><title>A ring</title>'
    . '<path fill="#4f46e5" fill-rule="evenodd" d="M50 10 A40 40 0 1 1 49.99 10 Z M50 30 A20 20 0 1 1 49.99 30 Z"/></svg>';

$document = Document::make(Page::a4()->body(Svg::fromString($ring)->width(mm(30))));
```


## PDF templates

`Page::template(PdfPage::file($path))` makes a page the size of a page of an existing PDF and draws that page behind everything else on it, so you can fill in a certificate or a letterhead. `PdfPage::file()` and `PdfPage::bytes()` take a `page` number, from 1, and a `password` for a protected PDF. The PDF is read when you call `Page::template()`, and a missing file, a file that isn't a PDF, a wrong password or a missing page throws `PdfUnreadable`. The template is drawn as it is, with its text and vector artwork kept sharp, and it's stored once however many pages use it. An encrypted document encrypts the template too.

`Page::overlay(Place ...)` puts content at points on the page, over everything else. `Place::at($x, $y, ...$nodes)` takes lengths from the page's top-left corner or percentages of its width and height, and `anchor()` picks which point of the content sits there, from `Anchor::TopLeft` (the default) to `Anchor::BottomRight`. Without `width()`, a place is as wide as its content, up to the page width; with one, a length or a percentage of the page width, percentages inside the place are of its width. The overlay repeats on every page the page template makes. A `Place` anywhere else throws a `LogicException`.

`PdfPage` also works anywhere in your content, like an image. HTML exports leave PDF pages and templates out and report them as dropped, and position places with CSS.

```php
use Papercut\{Column, Document, Page, PdfPage, Place, QrCode, Text};
use Papercut\Style\Anchor;
use function Papercut\{mm, percent, pt};

// Any PDF works as a template; this one is made with Papercut.
$template = Document::make(Page::a4()->landscape()->margin(mm(15))->body(
    Column::make(Text::make('Certificate of Completion')->size(pt(32))->bold()->center())
        ->border(pt(4), '#1e3a8a')->padding(mm(12)),
))->toPdf()->bytes();

$document = Document::make(
    Page::template(PdfPage::bytes($template))->overlay(
        Place::at('50%', '45%', Text::make('Ada Lovelace')->size(pt(36))->bold())->anchor(Anchor::Top),
        Place::at('85%', '80%', QrCode::make('https://example.com/verify/123')->width(percent(100)))
            ->width('12%')->anchor(Anchor::Center),
    ),
);
```

## Images and links

```php
use Papercut\{Column, Document, Image, Link, Page, Text};
use Papercut\Style\ImageFit;
use function Papercut\{mm, pt};

// A 1x1 PNG, inline to keep the example self-contained. Use Image::file('logo.png') in practice.
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

$document = Document::make(
    Page::a4()->margin(mm(20))->body(
        Column::make(
            Image::bytes($png)->fit(ImageFit::Contain)->width(mm(30))->height(mm(30)),
            Link::to('https://example.com', Text::make('A link to example.com')->underline()),
        )->gap(pt(8)),
    ),
);
```

## Fonts

Inter (Regular, Bold, Italic, BoldItalic) is bundled, subset and embedded; the 14 standard PDF fonts are never
used. Add your own families with a `FontSet` of `FontFace`s, pick them with `font()`, and list fallback families for glyphs
the primary font lacks.

```php
use Papercut\{Column, Document, Page, Text};
use Papercut\Export\PdfOptions;
use Papercut\Text\{FontFace, FontRegistry, FontSet};
use function Papercut\{mm, pt};

// Add your own family (paths to TTFs), here reusing the bundled files under another name:
$inter = dirname((new ReflectionClass(FontRegistry::class))->getFileName(), 3) . '/resources/fonts/Inter';
$fonts = (new FontSet())
    ->with(new FontFace('Brand', $inter . '/Inter-Regular.ttf', 400), new FontFace('Brand', $inter . '/Inter-Bold.ttf', 700))
    ->withFallbacks('Inter');

$document = Document::make(
    Page::a4()->margin(mm(20))->body(
        Column::make(
            Text::make('Set in Brand')->font('Brand')->size(pt(18)),
            Text::make('Brand bold')->font('Brand')->bold(),
        )->gap(pt(8)),
    ),
);
$options = new PdfOptions(fonts: $fonts);
// $document->toPdf($options)->save('out.pdf');
```

The backend shapes and reorders text within a single drawn run (kerning, ligatures). Right-to-left and bidirectional
paragraph layout (direction, reordering across line breaks, direction-aware alignment) is unsupported and untested
in v0.1, and so is HarfBuzz shaping.

## Fonts in HTML

`HtmlOptions::fontMode` decides how a browser gets the fonts of an HTML export:

- `FontMode::None` (default): only the `font-family` stack is written. The browser uses what it has.
- `FontMode::Embed`: the registered files are embedded as `data:` URIs in `@font-face` rules.
- `FontMode::Link`: the registered files are linked; the `fontUrl` callback returns each face's URL.
- `FontMode::Sources`: an ordered stack of font sources decides, family by family, where the browser gets the font. Pass the stack as `fontSources`. A source is a `FontSource` object or a closure `fn (FontRequest $r): ?FontAnswer`; return `null` to pass a family to the next source.

```php
use Papercut\{Document, Page, Text};
use Papercut\Export\{FontMode, HtmlOptions};
use Papercut\Html\{GoogleFonts, LocalFonts};

$document = Document::make(Page::a4()->body(Text::make('Hello')));

// Google Fonts
$google = new HtmlOptions(fullPage: true, fontMode: FontMode::Sources, fontSources: [new GoogleFonts()]);

// Bunny Fonts: same API, another host
$bunny = new HtmlOptions(fullPage: true, fontMode: FontMode::Sources, fontSources: [new GoogleFonts(host: 'https://fonts.bunny.net')]);

// Mixed: your own Acme font is embedded, everything else comes from Google
$mixed = new HtmlOptions(
    fullPage: true,
    fontMode: FontMode::Sources,
    fontSources: [LocalFonts::embed(['Acme']), new GoogleFonts()],
);

$html = $document->toHtml($google)->bytes();
```

A full page gets one `<link rel="stylesheet">` per stylesheet in `<head>`. A fragment has no head, so the same stylesheets are written as `@import` rules at the very start of its `<style>` block (a browser ignores an `@import` that follows another rule).

**Order matters.** Each family goes to the first source that answers for it, so put specific sources first and catch-all hosts last. `GoogleFonts` accepts every family unless you give it an allowlist, so a catch-all `GoogleFonts` placed above `LocalFonts` would take your own families. For the same reason Bunny above Google only makes sense when Bunny has an allowlist (`new GoogleFonts(host: 'https://fonts.bunny.net', families: ['Inter'])`).

A source that answers with an unsafe URL (a scheme other than http or https, a protocol-relative `//host`, control characters, an empty face list) is skipped, a warning is sent to `onWarning`, and the next source is asked. A family no source answers for is reported as `font not linked` (an approximation) and the browser falls back to the `font-family` stack.

**Privacy.** With a third-party host the visitor's browser sends its IP address to that host. LG München I (3 O 17493/20, January 2022) held that this can breach data protection law without consent. Bunny Fonts is a host that states it does not log IP addresses, but the request still reaches a third party: moving to it changes the host, not the question, and is no compliance guarantee. The fully private routes are `LocalFonts`, `FontMode::Embed` and `FontMode::Link`, which serve fonts you control.

**Content Security Policy.** Three directives are involved: `style-src` for the stylesheet host; `font-src` for the font domain (Google: `fonts.googleapis.com` for styles, `fonts.gstatic.com` for fonts; Bunny: `fonts.bunny.net` for both); and `font-src data:` for embedded faces (`LocalFonts::embed`, and `FontMode::Embed`).

**Limits.** The font stylesheets are built from the families and weights the document uses. Google rejects the stylesheet of a family it does not have, or of a weight it does not offer, and the browser then falls back silently. The library cannot see that, because it never fetches the stylesheet.

## Font cache

Where every request is a new process (PHP-FPM), each font face would be parsed again on every request. The default
backend keeps each parsed face in a file instead, and later processes restore it in a fraction of the time (Inter:
1.7 ms parsed, 0.2 ms restored). Each face gets one file of a few hundred KB in
`sys_get_temp_dir()/papercut-cache-<uid>`. The key covers the font's path, mtime and size plus the php-pdf version,
so an updated font or library simply gets a new entry. Entries unused for 30 days are pruned. Any problem (an
unwritable directory, a symlinked or foreign-owned one, a damaged file) falls back silently to parsing; output is
identical either way.

```php
use Papercut\{Document, Page, Text};
use Papercut\Export\PdfOptions;

$document = Document::make(Page::a4()->body(Text::make('Hello')));
$options = new PdfOptions(cacheDir: '/var/cache/myapp/papercut'); // your own directory
$options = new PdfOptions(cacheDir: false);                       // no disk cache
```

A service that renders PDFs all day can let opcache hold the parsed fonts in shared memory, at the cost of opcache
memory for every face ever cached:

```php
use Papercut\{Document, Page, Text};
use Papercut\Backend\PhpPdf\{FontStateCache, PhpPdfBackend};
use Papercut\Export\PdfOptions;

$document = Document::make(Page::a4()->body(Text::make('Hello')));
$options = new PdfOptions(backend: new PhpPdfBackend(fontCacheFormat: FontStateCache::PHP));
```

## Errors

Every error extends `Papercut\PapercutException` and says what is wrong and where.

| Exception | Cause |
|---|---|
| `UnknownAttribute` | A style attribute that does not exist; the message suggests the closest valid name. |
| `InvalidValue` | A value that cannot be parsed (`'12 parsecs'`, a bad colour, a bad track list). |
| `FontNotFound` | A family/weight/style that is not registered. |
| `ImageUnreadable` | A file or bytes that are not a supported image. |
| `LayoutOverflow` | Content that cannot fit on an empty page, such as an image with an explicit `height` taller than the page body. |

By default overflow throws. `new PdfOptions(overflow: OverflowMode::Clip)` clips instead and reports through
`onWarning`.

```php
use Papercut\{Column, Document, Page, PapercutException, Text};
use function Papercut\{mm, pt};

try {
    $text = Text::make('Oops')->attributes(['colour' => 'red']);
} catch (PapercutException $e) {
    $text = Text::make('Caught: ' . $e->getMessage());
}

$document = Document::make(Page::a4()->margin(mm(20))->body(Column::make($text)->gap(pt(4))));
```

## Examples

Runnable scripts in [`examples/core`](../../examples/core): an invoice, a multi-page transcript and a report.

## License

MIT. Bundled Inter font: SIL Open Font License 1.1 (`resources/fonts/Inter/LICENSE.txt`).
Color palette derived from Tailwind CSS (MIT). See [`NOTICE.md`](../../NOTICE.md).
