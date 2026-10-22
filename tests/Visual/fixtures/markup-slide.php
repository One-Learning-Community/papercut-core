<?php

declare(strict_types=1);


use Papercut\{Column, Document, Page};
use Papercut\Markup\{Markup, MarkupOptions, StyleMode};

use function Papercut\{mm, pt};

// Samples q105 (figure + three-level outline), q107 (paragraphs + figure with a zero-width no-break space) and
// q138 (linked image) from spec §11, in original style mode. Each src resolves to a generated PNG at the size its
// tag declares, so no binary fixture.
$pngs = [];
foreach (['9.42.58' => [221, 266], '9.52.59' => [647, 104], '12.33.24' => [831, 658]] as $time => [$w, $h]) {
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, (int) imagecolorallocate($im, 30, 90, 200));
    imagerectangle($im, 0, 0, $w - 1, $h - 1, 0xFFFFFF);
    imagefilledellipse($im, intdiv($w, 2), intdiv($h, 2), intdiv(min($w, $h), 2), intdiv(min($w, $h), 2), 0xFFDC00);
    ob_start();
    imagepng($im);
    $pngs[$time] = (string) ob_get_clean();
}
$images = static function (string $src) use ($pngs): ?string {
    foreach ($pngs as $time => $png) {
        if (str_contains($src, "at $time")) {
            return $png;
        }
    }
    return null;
};

/** @var array<string, string> $samples */
$samples = require __DIR__ . '/../../fixtures/markup/samples.php';
$options = new MarkupOptions(styles: StyleMode::Original, images: $images);

return Document::make(
    Page::letter()->margin(mm(15))->body(
        Column::make(
            Markup::html($samples['q105'], $options),
            Markup::html($samples['q107'], $options),
            Markup::html($samples['q138'], $options),
        )->gap(pt(16)),
    ),
)->createdAt(new DateTimeImmutable('2026-01-01T00:00:00Z'));
