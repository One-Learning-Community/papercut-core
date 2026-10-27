<?php

declare(strict_types=1);

namespace Papercut;

use Papercut\Backend\{PdfPageHandle, PdfPageLoader};
use Papercut\Concerns\HasStyle;
use Papercut\Exception\{InvalidValue, PdfUnreadable};

/**
 * A page of an existing PDF, drawn as it is: vector paths stay paths and its text stays text.
 * It is sized like an image, from the page's own size, and setting one side keeps the aspect
 * ratio. HTML output leaves it out, since a browser cannot draw it inline.
 */
final class PdfPage implements Styled
{
    use HasStyle;

    private function __construct(
        private readonly string $kind,
        private readonly string $value,
        private readonly int $page,
        private readonly string $password,
    ) {
        if ($page < 1) {
            throw InvalidValue::for('PDF page number', (string) $page)->because('pages are numbered from 1');
        }
    }

    /** The file is read when the document is laid out or exported. */
    public static function file(string $path, int $page = 1, #[\SensitiveParameter] string $password = ''): self
    {
        return new self('file', $path, $page, $password);
    }

    public static function bytes(string $bytes, int $page = 1, #[\SensitiveParameter] string $password = ''): self
    {
        return new self('bytes', $bytes, $page, $password);
    }

    /** @return array{kind: 'file'|'bytes', value: string} */
    public function source(): array
    {
        /** @var 'file'|'bytes' $kind */
        $kind = $this->kind;

        return ['kind' => $kind, 'value' => $this->value];
    }

    /** The page to draw, counting from 1. */
    public function pageNumber(): int
    {
        return $this->page;
    }

    /** @internal */
    public function password(): string
    {
        return $this->password;
    }

    /**
     * Reads the file, if it is one, and loads the page; errors about a file name it.
     *
     * @internal
     * @throws PdfUnreadable
     */
    public function load(PdfPageLoader $loader): PdfPageHandle
    {
        if ($this->kind === 'bytes') {
            return $loader->loadPdfPage($this->value, $this->page, $this->password);
        }
        $bytes = is_file($this->value) && is_readable($this->value) ? file_get_contents($this->value) : false;
        if ($bytes === false) {
            throw new PdfUnreadable(sprintf('Cannot read PDF "%s": file not found or not readable', $this->value));
        }
        try {
            return $loader->loadPdfPage($bytes, $this->page, $this->password);
        } catch (PdfUnreadable $e) {
            $named = preg_replace('/^Cannot (read|draw page \d+ of) (?:the )?PDF/', 'Cannot $1 PDF "' . addcslashes($this->value, '\\$') . '"', $e->getMessage(), 1);
            throw new PdfUnreadable($named ?? $e->getMessage(), 0, $e);
        }
    }

    public function debugName(): string
    {
        return 'pdf-page';
    }
}
