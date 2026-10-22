<?php

declare(strict_types=1);

namespace Papercut\Html;

/**
 * Decides where the browser gets one font family.
 *
 * Sources form a stack, asked in order for each family the document uses. Return
 * null to pass the family to the next source. The first usable answer wins, so put
 * catch-all hosts (those that accept every family) last.
 */
interface FontSource
{
    public function __invoke(FontRequest $request): ?FontAnswer;
}
