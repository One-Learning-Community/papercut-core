<?php

declare(strict_types=1);

namespace Papercut\Layout;

/** @internal Part of Papercut's engine, not its public API. It may change in any release. */
final readonly class LayoutResult
{
    private function __construct(
        public Status $status,
        public ?Fragment $fragment,
        public ?BreakToken $token,
    ) {
    }

    public static function complete(Fragment $fragment): self
    {
        return new self(Status::Complete, $fragment, null);
    }

    public static function partial(Fragment $fragment, BreakToken $token): self
    {
        return new self(Status::Partial, $fragment, $token);
    }

    public static function doesNotFit(): self
    {
        return new self(Status::DoesNotFit, null, null);
    }
}
