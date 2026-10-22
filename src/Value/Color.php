<?php

declare(strict_types=1);

namespace Papercut\Value;

use Papercut\Exception\InvalidValue;
use Papercut\Support\Suggest;

final readonly class Color implements \Stringable
{
    private function __construct(
        public int $r,
        public int $g,
        public int $b,
        public float $alpha,
    ) {
    }

    public static function rgb(int $r, int $g, int $b, float $alpha = 1.0): self
    {
        foreach ([$r, $g, $b] as $ch) {
            if ($ch < 0 || $ch > 255) {
                throw InvalidValue::for('color', sprintf('rgb(%d, %d, %d)', $r, $g, $b));
            }
        }
        if (!is_finite($alpha) || $alpha < 0 || $alpha > 1) {
            throw InvalidValue::for('color', sprintf('rgba(%d, %d, %d, %s)', $r, $g, $b, InvalidValue::number($alpha)));
        }
        return new self($r, $g, $b, round($alpha, 4));
    }

    public static function black(): self { return new self(0, 0, 0, 1.0); }
    public static function white(): self { return new self(255, 255, 255, 1.0); }
    public static function transparent(): self { return new self(0, 0, 0, 0.0); }

    public static function hex(string $input): self
    {
        if (!preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})\z/', trim($input), $m)) {
            throw InvalidValue::for('color', $input);
        }
        $h = $m[1];
        if (strlen($h) === 3) {
            $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        }
        $alpha = strlen($h) === 8 ? hexdec(substr($h, 6, 2)) / 255 : 1.0;
        return self::rgb((int) hexdec(substr($h, 0, 2)), (int) hexdec(substr($h, 2, 2)), (int) hexdec(substr($h, 4, 2)), $alpha);
    }

    public static function named(string $name): self
    {
        $key = strtolower(trim($name));
        if ($key === 'transparent') {
            return self::transparent();
        }
        $palette = self::palette();
        if (isset($palette[$key])) {
            return self::hex($palette[$key]);
        }
        throw InvalidValue::for('color', $name, self::suggestName($key, $palette));
    }

    public static function parse(string $input): self
    {
        $s = trim($input);
        if ($s === '') {
            throw InvalidValue::for('color', $input);
        }
        if ($s[0] === '#') {
            return self::hex($s);
        }
        // Three accepted shapes, each with one separator style: rgb(r, g, b), rgba(r, g, b, a), rgb(r g b [/ a]).
        if (preg_match('/^rgb\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*\)$/i', $s, $m)
            || preg_match('/^rgba\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d*\.?\d+)\s*\)$/i', $s, $m)
            || preg_match('/^rgba?\(\s*(\d{1,3})\s+(\d{1,3})\s+(\d{1,3})(?:\s*\/\s*(\d*\.?\d+))?\s*\)$/i', $s, $m)) {
            try {
                return self::rgb((int) $m[1], (int) $m[2], (int) $m[3], isset($m[4]) ? (float) $m[4] : 1.0);
            } catch (InvalidValue) {
                throw InvalidValue::for('color', $input);
            }
        }
        try {
            return self::named($s);
        } catch (InvalidValue $e) {
            throw InvalidValue::for('color', $input, $e->suggestion);
        }
    }

    public function withAlpha(float $alpha): self
    {
        return self::rgb($this->r, $this->g, $this->b, $alpha);
    }

    public function isOpaque(): bool { return $this->alpha >= 1.0; }
    public function isTransparent(): bool { return $this->alpha <= 0.0; }

    /** @return array{float,float,float} */
    public function toUnitRgb(): array
    {
        return [$this->r / 255, $this->g / 255, $this->b / 255];
    }

    public function __toString(): string
    {
        $hex = sprintf('#%02x%02x%02x', $this->r, $this->g, $this->b);
        return $this->isOpaque() ? $hex : $hex . sprintf('%02x', (int) round($this->alpha * 255));
    }

    /** @return array<string,string> */
    private static function palette(): array
    {
        static $palette = null;
        return $palette ??= require __DIR__ . '/../../resources/palette.php';
    }

    /** @param array<string,string> $palette */
    private static function suggestName(string $key, array $palette): ?string
    {
        if (preg_match('/^([a-z]+)(\d+)$/', $key, $m) && isset($palette[$m[1] . '-' . $m[2]])) {
            return $m[1] . '-' . $m[2];
        }
        // A bare family name ("red") suggests its middle shade.
        if (isset($palette[$key . '-500'])) {
            return $key . '-500';
        }
        // A known family with an unknown shade ("slate-550") is not a typo: no suggestion.
        if (preg_match('/^([a-z]+)-\d+$/', $key, $m) && isset($palette[$m[1] . '-500'])) {
            return null;
        }
        return Suggest::closest($key, array_map(strval(...), array_keys($palette)));
    }
}
