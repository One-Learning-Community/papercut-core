<?php

declare(strict_types=1);

namespace Papercut\Exception;

use Papercut\PapercutException;

final class InvalidValue extends PapercutException
{
    /** @param list<string> $locations */
    private function __construct(
        public readonly string $kind,
        public readonly string $input,
        public readonly ?string $suggestion,
        public readonly ?string $context,
        public readonly array $locations,
        public readonly ?string $reason,
        ?\Throwable $previous = null,
    ) {
        $msg = sprintf('Invalid %s "%s"', $kind, $input);
        if ($context !== null) {
            $msg .= ' for ' . $context;
        }
        if ($locations !== []) {
            $msg .= ' ' . implode(' ', $locations);
        }
        if ($reason !== null) {
            $msg .= ': ' . $reason;
        }
        $msg .= '.';
        if ($suggestion !== null) {
            $msg .= sprintf(' Did you mean "%s"?', $suggestion);
        }
        parent::__construct($msg, 0, $previous);
    }

    public static function for(string $kind, string $input, ?string $suggestion = null, ?\Throwable $previous = null): self
    {
        return new self($kind, $input, $suggestion, null, [], null, $previous);
    }

    /**
     * Formats a number for an error message. Non-finite floats become NAN, INF or -INF
     * directly, because casting NAN to string warns on PHP 8.5+.
     */
    public static function number(float|int $value): string
    {
        return match (true) {
            is_nan((float) $value) => 'NAN',
            $value === INF => 'INF',
            $value === -INF => '-INF',
            default => (string) $value,
        };
    }

    /** Names the attribute the value was given to: `for <attribute>`. */
    public function withContext(string $attribute): self
    {
        return new self($this->kind, $this->input, $this->suggestion, $attribute, $this->locations, $this->reason, $this);
    }

    /** Appends a location phrase such as `on <x-doc::page>`; repeated calls append left to right. */
    public function within(string $location): self
    {
        return new self($this->kind, $this->input, $this->suggestion, $this->context, [...$this->locations, $location], $this->reason, $this);
    }

    /** Adds a detail shown as `: <reason>` before the final period. */
    public function because(string $reason): self
    {
        return new self($this->kind, $this->input, $this->suggestion, $this->context, $this->locations, $reason, $this);
    }
}
