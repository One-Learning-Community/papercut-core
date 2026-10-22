<?php

declare(strict_types=1);

namespace Papercut;

use Papercut\Export\{ExportOptions, Exporter, HtmlExporter, HtmlOptions, Output, PdfExporter, PdfOptions};
use Papercut\Paint\DocumentMeta;

final class Document
{
    /** @var list<Page> */
    private array $pages;
    private ?string $title = null;
    private ?string $author = null;
    private ?string $subject = null;
    private ?string $keywords = null;
    private ?string $lang = null;
    private ?\DateTimeImmutable $createdAt = null;

    private function __construct()
    {
        $this->pages = [];
    }

    public static function make(Page ...$pages): self
    {
        $doc = new self();
        $doc->pages = array_values($pages);
        return $doc;
    }

    public function title(string $v): self
    {
        $clone = clone $this;
        $clone->title = $v;
        return $clone;
    }

    public function author(string $v): self
    {
        $clone = clone $this;
        $clone->author = $v;
        return $clone;
    }

    public function subject(string $v): self
    {
        $clone = clone $this;
        $clone->subject = $v;
        return $clone;
    }

    public function keywords(string $v): self
    {
        $clone = clone $this;
        $clone->keywords = $v;
        return $clone;
    }

    public function lang(string $v): self
    {
        $clone = clone $this;
        $clone->lang = $v;
        return $clone;
    }

    public function createdAt(\DateTimeImmutable $v): self
    {
        $clone = clone $this;
        $clone->createdAt = $v;
        return $clone;
    }

    /**
     * @return list<Page>
     */
    public function pages(): array
    {
        return $this->pages;
    }

    public function meta(): DocumentMeta
    {
        return new DocumentMeta($this->title, $this->author, $this->subject, $this->keywords, $this->lang, $this->createdAt);
    }

    public function export(Exporter $exporter, ?ExportOptions $options = null): Output
    {
        return $exporter->export($this, $options ?? $exporter->defaultOptions());
    }

    public function toPdf(?PdfOptions $options = null): Output
    {
        return $this->export(new PdfExporter(), $options);
    }

    public function toHtml(?HtmlOptions $options = null): Output
    {
        return $this->export(new HtmlExporter(), $options);
    }
}
