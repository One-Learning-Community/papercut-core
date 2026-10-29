<?php

declare(strict_types=1);

namespace Papercut\Text;

/**
 * A bounds-checked reader of a TrueType/OpenType file's table directory. Malformed or unreadable
 * files give null and missing tables give null; nothing here warns or throws.
 */
final class TrueTypeTables
{
    /** @param array<string, array{int, int}> $tables tag => [offset, length] */
    private function __construct(private readonly string $data, private readonly array $tables)
    {
    }

    public static function open(string $path): ?self
    {
        $data = @file_get_contents($path);
        if ($data === false || strlen($data) < 12) {
            return null;
        }
        $count = unpack('n', $data, 4);
        if ($count === false) {
            return null;
        }
        $tables = [];
        for ($i = 0, $n = (int) $count[1]; $i < $n; $i++) {
            $at = 12 + 16 * $i;
            if ($at + 16 > strlen($data)) {
                return null;
            }
            $rec = unpack('a4tag/Nsum/Noffset/Nlength', $data, $at);
            if ($rec === false) {
                return null;
            }
            $tables[(string) $rec['tag']] = [(int) $rec['offset'], (int) $rec['length']];
        }

        return new self($data, $tables);
    }

    /** The table's raw bytes, or null when it is absent or runs past the end of the file. */
    public function table(string $tag): ?string
    {
        if (!isset($this->tables[$tag])) {
            return null;
        }
        [$offset, $length] = $this->tables[$tag];
        if ($offset < 0 || $offset + $length > strlen($this->data)) {
            return null;
        }

        return substr($this->data, $offset, $length);
    }
}
