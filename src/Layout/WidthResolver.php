<?php

declare(strict_types=1);

namespace Papercut\Layout;

use Papercut\{OverflowMode, Table, View};
use Papercut\Backend\DefaultBackend;
use Papercut\Export\ExportOptions;
use Papercut\Text\FontRegistry;
use Papercut\Style\ComputedStyle;

/**
 * Horizontal sizes from the layout engine's own flex and table calculations,
 * without pagination, so layout-free exporters match the PDF horizontally.
 */
final class WidthResolver
{
    public function __construct(private readonly LayoutEngine $engine)
    {
    }

    /**
     * A resolver over a fresh layout context and engine, never shared with a PDF render:
     * table column warnings fire once per engine and must not be swallowed here.
     */
    public static function for(ExportOptions $options): self
    {
        $backend = DefaultBackend::make();
        $fonts = $options->fonts();
        $ctx = new LayoutContext(
            FontRegistry::fromSet($fonts, $backend),
            $options->theme(),
            $backend,
            OverflowMode::Throw,
            $options->onWarning(),
            $fonts->fallbacks,
        );

        return new self(new LayoutEngine($ctx));
    }

    /**
     * One list per flex line. $style is the row's own computed style; $available is the
     * width offered to its border box. x is relative to the content box.
     *
     * @return list<list<FlexSlot>>
     */
    public function flexLines(View $row, ComputedStyle $style, float $available): array
    {
        $lines = [];
        foreach ($this->engine->flexLayouter()->lines($row, $style, $available, $this->engine) as $line) {
            $lines[] = array_map(static fn (array $item): FlexSlot => new FlexSlot($item['index'], $item['x'], $item['main']), $line);
        }
        return $lines;
    }

    /**
     * One track per column as the PDF layout counts them.
     *
     * @return list<\Papercut\Value\Length|\Papercut\Value\Percent|\Papercut\Value\Fr|\Papercut\Value\Auto>
     */
    public static function tracks(Table $table): array
    {
        return TableLayouter::trackList($table);
    }

    /**
     * Column widths in pt; $style is the table's computed style, $available as in flexLines().
     *
     * @return list<float>
     */
    public function tableColumns(Table $table, ComputedStyle $style, float $available): array
    {
        return $this->engine->tableLayouter()->columns($table, $style, $available, $this->engine);
    }
}
