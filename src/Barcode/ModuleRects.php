<?php

declare(strict_types=1);

namespace Papercut\Barcode;

/**
 * Covers a grid's dark modules with few rectangles: each row's runs, merged with identical runs
 * in the rows below. Fewer shapes means a smaller file and fewer hairline seams in viewers.
 */
final class ModuleRects
{
    private function __construct()
    {
    }

    /**
     * @param list<list<bool>> $modules
     * @return list<array{x: int, y: int, w: int, h: int}> in modules, from the grid's top-left
     */
    public static function of(array $modules): array
    {
        /** @var array<string, int> $open run "x:w" => index into $rects, for runs continuing from the row above */
        $open = [];
        /** @var list<array{x: int, y: int, w: int, h: int}> $rects */
        $rects = [];
        foreach ($modules as $y => $row) {
            $next = [];
            $n = count($row);
            for ($x = 0; $x < $n; $x++) {
                if (!$row[$x]) {
                    continue;
                }
                $start = $x;
                while ($x + 1 < $n && $row[$x + 1]) {
                    $x++;
                }
                $key = $start . ':' . ($x - $start + 1);
                if (isset($open[$key])) {
                    $i = $open[$key];
                    $rects[$i] = ['x' => $rects[$i]['x'], 'y' => $rects[$i]['y'], 'w' => $rects[$i]['w'], 'h' => $rects[$i]['h'] + 1];
                    $next[$key] = $i;
                } else {
                    $rects[] = ['x' => $start, 'y' => $y, 'w' => $x - $start + 1, 'h' => 1];
                    $next[$key] = count($rects) - 1;
                }
            }
            $open = $next;
        }

        return $rects;
    }
}
