<?php

declare(strict_types=1);

namespace Papercut\Markup;

use Papercut\Span;
use Papercut\Text;
use Papercut\View;

/** @internal */
final class PlainText
{
    public static function convert(?string $text): View|Text
    {
        $text = mb_scrub($text ?? '', 'UTF-8');
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        $paragraphs = [];
        foreach (preg_split('/\n[ \t]*\n/', $text) ?: [] as $chunk) {
            $chunk = trim($chunk, " \t\n\r");
            if ($chunk === '') {
                continue;
            }
            $spans = [];
            foreach (explode("\n", $chunk) as $i => $line) {
                if ($i > 0) {
                    $spans[] = Span::break();
                }
                if ($line !== '') {
                    $spans[] = Span::text($line);
                }
            }
            $paragraphs[] = Text::spans(...$spans);
        }

        return match (count($paragraphs)) {
            0 => View::make(),
            1 => $paragraphs[0],
            default => View::make(...array_map(static fn (Text $p): Text => $p->withPreset('p'), $paragraphs)),
        };
    }
}
