<?php

declare(strict_types=1);


use Papercut\{Column, View, Document, Image, Page, Row, Text};
use Papercut\Style\ImageFit;

use function Papercut\{mm, pt};

// 60x30 gradient with a white frame so fit behaviour is visible; generated, so no binary fixture.
$im = imagecreatetruecolor(60, 30);
for ($x = 0; $x < 60; $x++) {
    imageline($im, $x, 0, $x, 29, ((int) ($x * 4.2) << 16) | (90 << 8) | (255 - (int) ($x * 4.2)));
}
imagerectangle($im, 0, 0, 59, 29, 0xFFFFFF);
imagefilledellipse($im, 30, 15, 12, 12, 0xFFDC00);
ob_start();
imagepng($im);
$png = (string) ob_get_clean();

$cell = static fn (ImageFit $fit): View => Column::make(
    Text::make($fit->value)->size(pt(8))->color('slate-500'),
    Image::bytes($png)->fit($fit)->width(mm(40))->height(mm(40))->border(pt(0.5), 'slate-400'),
)->gap(pt(2));

return Document::make(
    Page::a5()->margin(mm(12))->body(
        Column::make(
            Row::make($cell(ImageFit::Contain), $cell(ImageFit::Cover))->gap(pt(10)),
            Row::make($cell(ImageFit::Fill))->gap(pt(10)),
        )->gap(pt(10)),
    ),
)->createdAt(new DateTimeImmutable('2026-01-01T00:00:00Z'));
