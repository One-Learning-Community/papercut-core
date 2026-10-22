<?php

declare(strict_types=1);

namespace Papercut\Paint;

final readonly class DocumentMeta
{
    public function __construct(
        public ?string $title = null,
        public ?string $author = null,
        public ?string $subject = null,
        public ?string $keywords = null,
        public ?string $lang = null,
        public ?\DateTimeImmutable $createdAt = null,
    ) {
    }
}
