<?php

declare(strict_types=1);

/**
 * The prose benchmark document: a long article with a table of contents, 12 chapters of headings,
 * paragraphs with inline styles and links, justified text, lists and a quote, and a page footer.
 * Text layout dominates it, where the transcript is mostly a table.
 * tests/Feature/BenchmarkDocumentTest.php pins its page count.
 *
 * Returns the Document. Deterministic: no clock, no randomness.
 */

use Papercut\{Column, Document, ListNode, Page, PageNumber, Text, Toc};
use Papercut\Decorator\Blockquote;

use function Papercut\{mm, pt};

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!class_exists(Document::class) && is_file($autoload)) {
    require_once $autoload;
}

$words = explode(' ', 'the layout of a document follows its content from page to page while every heading stays with the '
    . 'text after it and long paragraphs break across columns tables and lists keep their spacing as fonts kern '
    . 'pairs like AV To Wa and Ty so that justified lines spread their words evenly between the margins');
$count = count($words);
/** The n-th sentence: a fixed slice of the word list, varied by position so lines break differently. */
$sentence = static function (int $n, int $length) use ($words, $count): string {
    $out = [];
    for ($i = 0; $i < $length; $i++) {
        $out[] = $words[($n * 7 + $i * 3) % $count];
    }
    return ucfirst(implode(' ', $out)) . '.';
};
$paragraph = static function (int $n, int $sentences) use ($sentence): string {
    $out = [];
    for ($i = 0; $i < $sentences; $i++) {
        $out[] = $sentence($n + $i, 12 + ($n + $i) % 9);
    }
    return implode(' ', $out);
};

$body = [Text::h1('Field Guide to Layout'), Toc::make()->levels(1, 2)->title('Contents')];
for ($chapter = 1; $chapter <= 12; $chapter++) {
    $body[] = Text::h1("Chapter $chapter: " . ucfirst($sentence($chapter, 4)));
    for ($section = 1; $section <= 3; $section++) {
        $n = $chapter * 10 + $section;
        $body[] = Text::h2("$chapter.$section " . ucfirst(rtrim($sentence($n, 3), '.')));
        $body[] = Text::p($paragraph($n, 6));
        $body[] = Text::markup('Some text is <b>bold</b>, some <i>italic</i>, some <u>underlined</u> and some <a href="https://example.com/' . $n . '">linked</a>. ' . $paragraph($n + 1, 3));
        $body[] = Text::p($paragraph($n + 2, 5))->justify();
        if ($section === 2) {
            $body[] = ListNode::unordered(...array_map(static fn (int $i): string => $sentence($n + $i, 10), range(1, 5)));
        }
    }
    $body[] = Blockquote::make(Text::p($paragraph($chapter * 3, 2)))->cite('A reader');
}

return Document::make(
    Page::a4()->margin(mm(20))
        ->body(Column::make(...$body)->gap(pt(6)))
        ->footer(PageNumber::make('Page {page} of {total}')->center()),
)->title('Field Guide to Layout')->createdAt(new DateTimeImmutable('2026-01-01T00:00:00Z'));
