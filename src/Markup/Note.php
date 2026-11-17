<?php

declare(strict_types=1);

namespace Quoin\Markup;

use Quoin\Export\FidelityLevel;

/** A fidelity warning attached to a converted node; `where` is the position inside the converted markup. */
final readonly class Note
{
    public function __construct(
        public FidelityLevel $level,
        public string $feature,
        public string $where = '',
    ) {
    }
}
