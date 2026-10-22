<?php

declare(strict_types=1);

namespace Papercut\Export;

final readonly class Output
{
    public function __construct(
        private string $bytes,
        private string $mime,
        private string $extension,
    ) {
    }

    public function bytes(): string
    {
        return $this->bytes;
    }

    public function mime(): string
    {
        return $this->mime;
    }

    public function extension(): string
    {
        return $this->extension;
    }

    public function save(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            throw new \RuntimeException("Directory does not exist: $dir");
        }
        error_clear_last();
        $written = @file_put_contents($path, $this->bytes);
        if ($written === false || $written < strlen($this->bytes)) {
            $error = error_get_last()['message'] ?? sprintf('wrote %d of %d bytes', (int) $written, strlen($this->bytes));
            throw new \RuntimeException(sprintf('Could not write output to "%s": %s', $path, $error));
        }
    }

    /** Writes the bytes to php://output and returns the number of bytes written. */
    public function stream(): int
    {
        $out = fopen('php://output', 'wb');
        if ($out === false) {
            throw new \RuntimeException('Could not open php://output');
        }
        $written = fwrite($out, $this->bytes);
        fclose($out);
        if ($written === false || $written < strlen($this->bytes)) {
            throw new \RuntimeException(sprintf('Could not write output to php://output: wrote %d of %d bytes', (int) $written, strlen($this->bytes)));
        }

        return $written;
    }
}
