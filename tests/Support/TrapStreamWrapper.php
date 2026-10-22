<?php

declare(strict_types=1);

namespace Papercut\Tests\Support;

/**
 * Stands in for the http, https, file and data stream wrappers while a test
 * runs: any URL opened through one of them is recorded and refused with an
 * exception. Filesystem paths under the allowed roots (autoloading, fonts,
 * temporary files) pass through to the real filesystem, so the code under test
 * still runs; any other path is refused too. PHP hands `file:///etc/passwd` to
 * the `file` wrapper as `/etc/passwd`, so the roots are what catch it.
 */
final class TrapStreamWrapper
{
    public const array SCHEMES = ['http', 'https', 'file', 'data'];

    /** @var list<string> every URL or path refused since install() */
    public static array $calls = [];

    /** @var list<string> */
    private static array $roots = [];

    /** @var resource|null */
    public $context;

    /** @var resource|null */
    private $handle;

    /** @var resource|null */
    private $dir;

    /**
     * Runs $fn with the trap installed and returns the URLs and paths it
     * refused; the real wrappers come back afterwards whatever happens.
     *
     * @param list<string> $roots directories whose files may be read and written
     * @param \Closure(): void $fn
     * @return list<string>
     */
    public static function during(array $roots, \Closure $fn): array
    {
        self::$calls = [];
        self::$roots = array_map(static fn (string $r): string => rtrim((string) realpath($r), '/') . '/', $roots);
        self::install();
        try {
            $fn();
        } finally {
            self::uninstall();
        }
        return self::$calls;
    }

    private static function install(): void
    {
        foreach (self::SCHEMES as $scheme) {
            stream_wrapper_unregister($scheme);
            stream_wrapper_register($scheme, self::class);
        }
    }

    private static function uninstall(): void
    {
        foreach (self::SCHEMES as $scheme) {
            @stream_wrapper_restore($scheme);
        }
    }

    /**
     * Refuses a URL with a scheme; runs a plain-path operation against the real
     * filesystem, with the real `file` wrapper back for its duration.
     *
     * @template T
     * @param \Closure(): T $op
     * @return T
     */
    private static function plain(string $path, \Closure $op): mixed
    {
        if (!self::allowed($path)) {
            self::$calls[] = $path;
            throw new \RuntimeException('Stream access refused in test: ' . $path);
        }
        stream_wrapper_restore('file');
        try {
            return $op();
        } finally {
            stream_wrapper_unregister('file');
            stream_wrapper_register('file', self::class);
        }
    }

    /** A filesystem path (not a URL) inside one of the roots. */
    private static function allowed(string $path): bool
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $path) === 1 || str_contains($path, "\0")) {
            return false;
        }
        $absolute = str_starts_with($path, '/') ? $path : getcwd() . '/' . $path;
        $real = realpath($absolute);
        $resolved = $real === false ? $absolute : $real;
        if (in_array('..', explode('/', $resolved), true)) {
            return false;
        }
        foreach (self::$roots as $root) {
            if (str_starts_with($resolved . '/', $root)) {
                return true;
            }
        }
        return false;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        $handle = self::plain($path, fn () => ($options & STREAM_USE_PATH) !== 0 ? fopen($path, $mode, true) : fopen($path, $mode));
        if ($handle === false) {
            return false;
        }
        $this->handle = $handle;
        return true;
    }

    public function stream_read(int $count): string|false
    {
        return $this->handle === null ? false : fread($this->handle, max(1, $count));
    }

    public function stream_write(string $data): int|false
    {
        return $this->handle === null ? false : fwrite($this->handle, $data);
    }

    public function stream_eof(): bool
    {
        return $this->handle === null || feof($this->handle);
    }

    public function stream_tell(): int|false
    {
        return $this->handle === null ? false : ftell($this->handle);
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        return $this->handle !== null && fseek($this->handle, $offset, $whence) === 0;
    }

    public function stream_flush(): bool
    {
        return $this->handle !== null && fflush($this->handle);
    }

    public function stream_close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    /** @param int<0, 7> $operation */
    public function stream_lock(int $operation): bool
    {
        return $this->handle !== null && flock($this->handle, $operation);
    }

    public function stream_truncate(int $size): bool
    {
        return $this->handle !== null && ftruncate($this->handle, max(0, $size));
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }

    /** @return array<int|string, int>|false */
    public function stream_stat(): array|false
    {
        return $this->handle === null ? false : fstat($this->handle);
    }

    /** @return array<int|string, int>|false */
    public function url_stat(string $path, int $flags): array|false
    {
        return self::plain($path, static fn () => ($flags & STREAM_URL_STAT_QUIET) !== 0
            ? @(($flags & STREAM_URL_STAT_LINK) !== 0 ? lstat($path) : stat($path))
            : (($flags & STREAM_URL_STAT_LINK) !== 0 ? lstat($path) : stat($path)));
    }

    public function stream_metadata(string $path, int $option, mixed $value): bool
    {
        return self::plain($path, static fn (): bool => match ($option) {
            STREAM_META_TOUCH => is_array($value) && isset($value[0], $value[1]) ? touch($path, (int) $value[0], (int) $value[1]) : touch($path),
            STREAM_META_ACCESS => chmod($path, is_int($value) ? $value : 0o644),
            default => false,
        });
    }

    public function unlink(string $path): bool
    {
        return self::plain($path, static fn (): bool => unlink($path));
    }

    public function rename(string $from, string $to): bool
    {
        return self::plain($from, static fn (): bool => self::plain($to, static fn (): bool => rename($from, $to)));
    }

    public function mkdir(string $path, int $mode, int $options): bool
    {
        return self::plain($path, static fn (): bool => mkdir($path, $mode, ($options & STREAM_MKDIR_RECURSIVE) !== 0));
    }

    public function rmdir(string $path, int $options): bool
    {
        return self::plain($path, static fn (): bool => rmdir($path));
    }

    public function dir_opendir(string $path, int $options): bool
    {
        $dir = self::plain($path, static fn () => opendir($path));
        if ($dir === false) {
            return false;
        }
        $this->dir = $dir;
        return true;
    }

    public function dir_readdir(): string|false
    {
        return $this->dir === null ? false : readdir($this->dir);
    }

    public function dir_rewinddir(): bool
    {
        if ($this->dir === null) {
            return false;
        }
        rewinddir($this->dir);
        return true;
    }

    public function dir_closedir(): bool
    {
        if ($this->dir !== null) {
            closedir($this->dir);
            $this->dir = null;
        }
        return true;
    }
}
