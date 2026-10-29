<?php

declare(strict_types=1);

namespace Papercut\Text;

/** What a font file says about its own kind: a class, its PANOSE classification and whether it is fixed pitch. */
final readonly class FontInfo
{
    /** @param ?string $panose the 10 PANOSE bytes as 20 uppercase hex characters, null without an OS/2 table */
    public function __construct(
        public FontClass $class,
        public ?string $panose,
        public bool $fixedPitch,
    ) {
    }

    /** Unreadable or malformed files give class Other, no PANOSE and variable pitch. */
    public static function read(string $path): self
    {
        static $cache = [];
        $key = $path . '|' . (int) @filemtime($path) . '|' . (int) @filesize($path);

        return $cache[$key] ??= self::parse($path);
    }

    private static function parse(string $path): self
    {
        $tables = TrueTypeTables::open($path);
        if ($tables === null) {
            return new self(FontClass::Other, null, false);
        }
        $post = $tables->table('post');
        $flag = $post !== null && strlen($post) >= 16 ? unpack('N', $post, 12) : false;
        $fixed = $flag !== false && $flag[1] !== 0;
        $os2 = $tables->table('OS/2');
        $panose = $os2 !== null && strlen($os2) >= 42 ? substr($os2, 32, 10) : null;
        if ($panose === null) {
            return new self($fixed ? FontClass::Mono : FontClass::Other, null, $fixed);
        }
        $kind = ord($panose[0]);
        $class = match (true) {
            $fixed => FontClass::Mono,
            $kind === 2 => in_array(ord($panose[1]), [0, 1, 11, 12, 13, 14, 15], true) ? FontClass::Sans : FontClass::Serif,
            $kind === 3 => FontClass::Script,
            $kind === 4 => FontClass::Decorative,
            default => FontClass::Other, // any (0) and no fit (1) say nothing, pictorial (5), and kinds PANOSE 1.0 does not define
        };

        return new self($class, strtoupper(bin2hex($panose)), $fixed);
    }
}
