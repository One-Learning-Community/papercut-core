<?php

declare(strict_types=1);

namespace Papercut\Text;

/** The broad kind of a font. The value is the Word `w:family` hint. */
enum FontClass: string
{
    case Sans = 'swiss';
    case Serif = 'roman';
    case Mono = 'modern';
    case Script = 'script';
    case Decorative = 'decorative';
    case Other = 'auto';

    /** The CSS generic family list to fall back on. */
    public function cssGeneric(): string
    {
        return match ($this) {
            self::Sans, self::Script, self::Decorative, self::Other => 'system-ui, sans-serif',
            self::Serif => 'serif',
            self::Mono => 'monospace',
        };
    }
}
