<?php

declare(strict_types=1);

namespace Papercut\Html;

use Papercut\Text\FontStyle;

/** One weight and style a document uses from a family. */
final readonly class FontVariant
{
    public function __construct(public int $weight, public FontStyle $style) {}
}
