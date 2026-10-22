<?php

declare(strict_types=1);

namespace Papercut\Html;

use Papercut\Text\FontStyle;

/** One font file a source offers for a weight and style. */
final readonly class FontFaceSrc
{
    public function __construct(public int $weight, public FontStyle $style, public string $url, public string $format) {}
}
