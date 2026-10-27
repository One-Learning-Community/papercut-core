<?php

declare(strict_types=1);

namespace Papercut\Backend\PhpPdf;

use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;

/**
 * Keeps each parsed font (cmap, metrics, names, kerning) in a file, so a new
 * process restores it with TtfFile::fromState() instead of parsing the font.
 * In PHP-FPM, where every request is a new process, that is about 1-2 ms per
 * face (Inter: 1.7 ms parsed, 0.2 ms restored).
 *
 * Two file formats. SERIALIZE (the default) is unserialized into the request
 * that needs the font and costs nothing between requests. PHP is an array
 * file that opcache keeps in shared memory for every worker: the fastest
 * restore, for services that render PDFs all day, at the cost of opcache
 * memory for every font face ever cached.
 *
 * Entries are keyed by the font's real path, mtime and size, php-pdf's version
 * and state format, and this cache's format. They are written once (temp file
 * + rename) and never changed, so opcache with validate_timestamps=0 stays
 * correct; entries unused for 30 days are pruned. Any problem (an unusable,
 * symlinked or foreign directory, a damaged or foreign entry, a state php-pdf
 * refuses) falls back to parsing: the font, and the output, are the same.
 */
final class FontStateCache
{
    /** A serialize() file, read into the memory of each request that uses the font. */
    public const string SERIALIZE = 'ser';
    /** A PHP array file that opcache holds in shared memory; compiled on every read without opcache. */
    public const string PHP = 'php';

    private const int FORMAT = 1;
    private const string MARKER = 'papercut-font';
    private const int MAX_AGE = 30 * 86400;
    /** An entry read this long after its last touch is touched again, to keep it clear of pruning. */
    private const int TOUCH_AFTER = 86400;
    /** Same bound as php-pdf's own cache of parsed fonts. */
    private const int MEMO_LIMIT = 24;

    /** @var array<string, TtfFile> fonts loaded in this process, by path, mtime and size */
    private static array $memo = [];
    /** @var array<string, true> directories already pruned in this process */
    private static array $pruned = [];

    /** $dir null keeps fonts in this process only. */
    public function __construct(private readonly ?string $dir, private readonly string $format = self::SERIALIZE)
    {
        if ($format !== self::SERIALIZE && $format !== self::PHP) {
            throw new \InvalidArgumentException(sprintf('Unknown font cache format "%s"; expected FontStateCache::SERIALIZE or FontStateCache::PHP', $format));
        }
    }

    public static function defaultDir(): string
    {
        // per user, so one account's cache never blocks or feeds another's
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'papercut-cache' . (function_exists('posix_geteuid') ? '-' . posix_geteuid() : '');
    }

    /** Forgets the fonts loaded in this process (for long-lived workers and tests); the files stay. */
    public static function forget(): void
    {
        self::$memo = [];
    }

    /**
     * The parsed font at $path. Throws what TtfFile throws for a font that cannot be
     * read or parsed; never for a cache problem.
     */
    public function load(string $path): TtfFile
    {
        clearstatcache(true, $path);
        $mtime = @filemtime($path);
        $size = @filesize($path);
        if ($mtime === false || $size === false) {
            return TtfFile::fromFile($path);
        }
        $key = $path . '@' . $mtime . ':' . $size;
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }
        $file = $this->dir === null ? null : $this->file($key);
        $ttf = $file === null ? null : $this->read($file, $path);
        if ($ttf === null) {
            $ttf = TtfFile::fromFile($path);
            if ($file !== null) {
                $this->write($file, $ttf);
            }
        }
        if (count(self::$memo) >= self::MEMO_LIMIT) {
            array_shift(self::$memo); // the oldest: a hit does not move an entry
        }
        return self::$memo[$key] = $ttf;
    }

    private function file(string $key): string
    {
        $version = sha1(implode("\0", [self::FORMAT, TtfFile::STATE_FORMAT, self::libraryVersion(), $key]));
        return $this->dir . DIRECTORY_SEPARATOR . 'font-' . $version . '.' . $this->format;
    }

    private function read(string $file, string $path): ?TtfFile
    {
        try {
            $this->prune();
            if (!is_file($file) || !$this->trustedDir() || !self::trustedFile($file)) {
                return null;
            }
            if ($this->format === self::PHP) {
                $payload = include $file;
            } else {
                $bytes = @file_get_contents($file);
                $payload = $bytes === false ? null : @unserialize($bytes, ['allowed_classes' => false]);
            }
            if (!is_array($payload) || ($payload[self::MARKER] ?? null) !== self::FORMAT || !is_array($payload['state'] ?? null)) {
                return null;
            }
            $bytes = @file_get_contents($path);
            if ($bytes === false) {
                return null;
            }
            /** @var array<string, mixed> $state */
            $state = $payload['state'];
            // refuses a state of another php-pdf layout, of another font file, or a damaged one
            $ttf = TtfFile::fromState($state, $bytes);
            $touched = @filemtime($file);
            if ($touched !== false && $touched < time() - self::TOUCH_AFTER) {
                @touch($file);
            }
            return $ttf;
        } catch (\Throwable) {
            return null; // parsed instead, and the entry rewritten
        }
    }

    private function write(string $file, TtfFile $ttf): void
    {
        try {
            $dir = (string) $this->dir;
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                return;
            }
            if (!$this->trustedDir() || !is_writable($dir)) {
                return;
            }
            $payload = [self::MARKER => self::FORMAT, 'state' => $ttf->exportState()];
            $code = $this->format === self::PHP ? '<?php return ' . var_export($payload, true) . ";\n" : serialize($payload);
            $tmp = @tempnam($dir, 'tmp-');
            if ($tmp === false) {
                return;
            }
            if (@file_put_contents($tmp, $code) !== strlen($code) || !@chmod($tmp, 0600) || !@rename($tmp, $file)) {
                @unlink($tmp);
            }
        } catch (\Throwable) {
            // a cache problem never fails a render
        }
    }

    /** Removes entries untouched for 30 days; at most once per directory and process. */
    private function prune(): void
    {
        $dir = (string) $this->dir;
        if (isset(self::$pruned[$dir]) || !is_dir($dir) || !$this->trustedDir()) {
            return;
        }
        self::$pruned[$dir] = true;
        $cutoff = time() - self::MAX_AGE;
        foreach (glob($dir . DIRECTORY_SEPARATOR . 'font-*') ?: [] as $entry) {
            if (!str_ends_with($entry, '.' . self::SERIALIZE) && !str_ends_with($entry, '.' . self::PHP)) {
                continue;
            }
            $mtime = @filemtime($entry);
            if ($mtime !== false && $mtime < $cutoff) {
                @unlink($entry);
            }
        }
    }

    /**
     * A symlink, a directory owned by someone else, or one writable by group or others
     * (e.g. planted in a shared temp dir), is never read, written or pruned.
     */
    private function trustedDir(): bool
    {
        $dir = (string) $this->dir;
        clearstatcache(true, $dir);
        if (is_link($dir)) {
            return false;
        }
        $mode = @fileperms($dir);
        if ($mode !== false && ($mode & 0022) !== 0) {
            return false;
        }
        return self::ownedByMe($dir);
    }

    /** A PHP entry is code that gets included: it must be ours and writable by nobody else. */
    private static function trustedFile(string $file): bool
    {
        if (is_link($file)) {
            return false;
        }
        $mode = @fileperms($file);
        return $mode !== false && ($mode & 0022) === 0 && self::ownedByMe($file);
    }

    /** True when $path is missing, owned by the effective user, or ownership cannot be checked (no posix). */
    private static function ownedByMe(string $path): bool
    {
        if (!function_exists('posix_geteuid')) {
            return true;
        }
        $owner = @fileowner($path);
        return $owner === false || $owner === posix_geteuid();
    }

    private static function libraryVersion(): string
    {
        if (class_exists(\Composer\InstalledVersions::class) && \Composer\InstalledVersions::isInstalled('dskripchenko/php-pdf')) {
            return \Composer\InstalledVersions::getVersion('dskripchenko/php-pdf') . '@' . \Composer\InstalledVersions::getReference('dskripchenko/php-pdf');
        }
        return 'unknown';
    }
}
