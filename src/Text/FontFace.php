<?php

declare(strict_types=1);

namespace Quoin\Text;

final readonly class FontFace
{
    public function __construct(
        public string $family,
        public string $path,
        public int $weight = 400,
        public FontStyle $style = FontStyle::Normal,
    ) {
    }
}
