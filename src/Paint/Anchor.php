<?php

declare(strict_types=1);

namespace Papercut\Paint;

/**
 * A named place on a page that links to a table of contents jump to. Draws nothing.
 *
 * @internal Part of the display list between layout and the PDF writer. It may change in any release.
 */
final readonly class Anchor implements Op
{
    public function __construct(public string $name, public float $x, public float $y)
    {
    }
}
