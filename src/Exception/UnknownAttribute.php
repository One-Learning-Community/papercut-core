<?php

declare(strict_types=1);

namespace Papercut\Exception;

use Papercut\PapercutException;

final class UnknownAttribute extends PapercutException
{
    /** @param list<string> $locations */
    private function __construct(
        public readonly string $name,
        public readonly ?string $suggestion,
        public readonly array $locations,
        public readonly string $noun,
        ?\Throwable $previous = null,
    ) {
        $msg = sprintf('Unknown %s "%s"', $noun, $name);
        if ($locations !== []) {
            $msg .= ' ' . implode(' ', $locations);
        }
        $msg .= '.';
        if ($suggestion !== null) {
            $msg .= sprintf(' Did you mean "%s"?', $suggestion);
        }
        parent::__construct($msg, 0, $previous);
    }

    public static function for(string $name, ?string $suggestion = null, string $noun = 'attribute'): self
    {
        return new self($name, $suggestion, [], $noun);
    }

    /** Appends a location phrase such as `on <x-doc::column>`; repeated calls append left to right. */
    public function within(string $location): self
    {
        return new self($this->name, $this->suggestion, [...$this->locations, $location], $this->noun, $this);
    }
}
