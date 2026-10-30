<?php

declare(strict_types=1);

namespace Papercut\Code;

use Papercut\Value\Color;

final class CodeTheme
{
    public const string DEFAULT = 'papercut-light';

    public static function defaultPath(): string
    {
        return dirname(__DIR__, 2) . '/resources/code/papercut-light.json';
    }

    /**
     * @return array{background: Color, foreground: Color}
     * @throws \InvalidArgumentException when the file is unreadable or lacks the editor colours
     */
    public static function editorColors(string $path): array
    {
        $raw = is_file($path) ? @file_get_contents($path) : false;
        if ($raw === false) {
            throw new \InvalidArgumentException(sprintf('Cannot read the code theme file %s.', $path));
        }
        $json = json_decode($raw, true);
        $colors = is_array($json) && is_array($json['colors'] ?? null) ? $json['colors'] : [];
        $background = $colors['editor.background'] ?? null;
        $foreground = $colors['editor.foreground'] ?? null;
        if (!is_string($background) || !is_string($foreground)) {
            throw new \InvalidArgumentException(sprintf('The code theme file %s has no colors "editor.background" and "editor.foreground".', $path));
        }
        try {
            return ['background' => Color::parse($background), 'foreground' => Color::parse($foreground)];
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException(sprintf('The code theme file %s has an invalid editor colour: %s', $path, $e->getMessage()), 0, $e);
        }
    }
}
