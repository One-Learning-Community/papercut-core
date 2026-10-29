<?php

declare(strict_types=1);

use Papercut\Markup\Markup;
use Papercut\{Column, Document, Page};

use function Papercut\{mm, pt};

// Generic, made-up content: preformatted blocks (leading spaces, a tab, a blank line, a line longer than the box),
// a <pre><code> pair, and inline code, kbd, samp and tt in running text.
$html = <<<'HTML'
<h2>Preformatted text</h2>
<p>Run <code>make build</code> and press <kbd>Ctrl</kbd> then <kbd>C</kbd>. The tool prints <samp>done</samp> in a <tt>fixed</tt> face.</p>
<pre>function total(items) {
    let sum = 0;
	for (const item of items) {
        sum += item.price;
    }

    return sum;
}</pre>
<p>The same listing as a code element inside a pre element:</p>
<pre><code>name:    sample
version: 1.2.0
tags:    [alpha,  beta,  gamma]
summary: a deliberately long line that is wider than the box so that it has to wrap onto another line</code></pre>
<p>Text after the block goes back to the paragraph style.</p>
HTML;

return Document::make(
    Page::a5()->margin(mm(12))->body(
        Column::make(Markup::html($html))->gap(pt(6)),
    ),
)->createdAt(new DateTimeImmutable('2026-01-01T00:00:00Z'));
