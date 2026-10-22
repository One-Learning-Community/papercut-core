<?php

declare(strict_types=1);

namespace Papercut\Value;

/**
 * Not readonly: PHP forbids static properties in readonly classes, and the
 * shared instance is held in a static. The class has no instance state.
 */
final class Auto implements \Stringable
{
    private static ?self $instance = null;

    private function __construct()
    {
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function __toString(): string
    {
        return 'auto';
    }
}
