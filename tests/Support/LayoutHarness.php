<?php

declare(strict_types=1);

namespace Papercut\Tests\Support;

use Papercut\{Component, Node, OverflowMode};
use Papercut\Layout\{BreakToken, Constraints, LayoutContext, LayoutEngine, LayoutResult};
use Papercut\Style\{ComputedStyle, Theme};
use Papercut\Text\FontRegistry;

final class LayoutHarness
{
    /** @var list<string> */
    public array $warnings = [];
    public LayoutEngine $engine;
    private Theme $theme;

    /** @param list<string> $fallbackFamilies */
    public function __construct(?Theme $theme = null, OverflowMode $overflow = OverflowMode::Throw, ?FakeBackend $backend = null, ?FontRegistry $fonts = null, array $fallbackFamilies = [])
    {
        $backend ??= new FakeBackend();
        $this->theme = $theme ?? Theme::default();
        $ctx = new LayoutContext($fonts ?? FontRegistry::withDefaults($backend), $theme ?? Theme::default(), $backend, $overflow, function (string $w): void { $this->warnings[] = $w; }, $fallbackFamilies);
        $this->engine = new LayoutEngine($ctx);
    }

    public function layout(Node|Component $node, float $width, ?float $height = null, ?BreakToken $token = null, bool $fresh = true): LayoutResult
    {
        return $this->engine->layout($node, ComputedStyle::root($this->theme), new Constraints($width, $height, $fresh), $token);
    }

    /**
     * Font id and text of every TextRun in a fragment tree, depth-first.
     *
     * @return list<array{font: string, text: string}>
     */
    public static function fontRuns(?\Papercut\Layout\Fragment $f): array
    {
        if ($f === null) {
            return [];
        }
        $out = [];
        foreach ($f->ops() as $op) {
            if ($op instanceof \Papercut\Paint\TextRun) {
                $out[] = ['font' => $op->font->id(), 'text' => $op->text];
            }
        }
        foreach ($f->children() as $c) {
            array_push($out, ...self::fontRuns($c['fragment']));
        }
        return $out;
    }

    /**
     * All TextRun texts in a fragment tree, depth-first, with absolute positions.
     *
     * @return list<array{text: string, x: float, baseline: float}>
     */
    public static function runs(?\Papercut\Layout\Fragment $f, float $ox = 0, float $oy = 0): array
    {
        if ($f === null) {
            return [];
        }
        $out = [];
        foreach ($f->ops() as $op) {
            if ($op instanceof \Papercut\Paint\TextRun) {
                $out[] = ['text' => $op->text, 'x' => $ox + $op->x, 'baseline' => $oy + $op->baseline];
            }
        }
        foreach ($f->children() as $c) {
            array_push($out, ...self::runs($c['fragment'], $ox + $c['x'], $oy + $c['y']));
        }
        return $out;
    }
}
