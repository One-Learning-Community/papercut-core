<?php

declare(strict_types=1);

use Papercut\Decorator\CodeBlock;
use Papercut\{Column, Document, Page, Text};

use function Papercut\{mm, pt};

// Generic, made-up content: one block per look (default, a dark theme, strip, caption), a long block that
// crosses a page break, and a plain-text block.
$php = <<<'CODE'
<?php

// Sum the prices in a basket.
function total(array $items): float
{
    $sum = 0.0;
    foreach ($items as $item) {
        $sum += $item['price'] * 2; /* doubled */
    }

    return $sum;
}
CODE;

$ts = <<<'CODE'
interface Basket {
  items: string[];
  total(): number;
}

const label: string = `basket-${1 + 2}`;
CODE;

$sql = <<<'CODE'
-- Orders from the last week
SELECT id, total
FROM orders
WHERE created_at > NOW() - INTERVAL 7 DAY
ORDER BY total DESC;
CODE;

$python = <<<'CODE'
def greet(name):
    # say hello
    return f"hello, {name}"
CODE;

$long = "<?php\n\nfunction long_listing(\$input)\n{\n" . implode("\n", array_map(
    static fn (int $i): string => "    \$row$i = compute_$i(\$input, " . ($i * 3) . ");",
    range(1, 68),
)) . "\n}\n";

$text = "plain  text with\ttabs\n  and indentation\n\nno colours here";

return Document::make(
    Page::a5()->margin(mm(12))->body(
        Column::make(
            Text::make('Code blocks'),
            CodeBlock::make($php)->language('php'),
            CodeBlock::make($ts)->language('typescript')->theme('github-dark'),
            CodeBlock::make($sql)->language('sql')->variant('strip'),
            CodeBlock::make($python)->language('python')->variant('caption'),
            CodeBlock::make($long)->language('php'),
            CodeBlock::make($text)->language('text'),
        )->gap(pt(8)),
    ),
)->createdAt(new DateTimeImmutable('2026-01-01T00:00:00Z'));
