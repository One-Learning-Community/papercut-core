<?php

declare(strict_types=1);

namespace Papercut\Markup;

use Papercut\Export\FidelityLevel;

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
