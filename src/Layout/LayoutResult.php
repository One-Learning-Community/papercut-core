<?php

declare(strict_types=1);

namespace Papercut\Layout;

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
