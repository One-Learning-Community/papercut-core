<?php

declare(strict_types=1);

namespace Papercut\Paint;

use Papercut\Export\Encryption;

final readonly class DocumentMeta
{
    public function __construct(
        public ?string $title = null,
        public ?string $author = null,
        public ?string $subject = null,
        public ?string $keywords = null,
        public ?string $lang = null,
        public ?\DateTimeImmutable $createdAt = null,
        public ?Encryption $encryption = null,
    ) {
    }

    public function withEncryption(?Encryption $encryption): self
    {
        return new self($this->title, $this->author, $this->subject, $this->keywords, $this->lang, $this->createdAt, $encryption);
    }
}
