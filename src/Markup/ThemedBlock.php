<?php

declare(strict_types=1);

namespace Papercut\Markup;

use Papercut\Decorator\CodeBlock;
use Papercut\Style\{Style, Theme};
use Papercut\ThemedComponent;
use Papercut\View;

/**
 * A code block from markup, rendered when it is laid out so the page's code theme reaches it.
 * Styles the converter applies to it (as it would to the rendered View) are kept and applied then.
 */
final class ThemedBlock implements ThemedComponent
{
    /** @var array<string, View> the dressed view by the code theme it used, so one expansion is one node */
    private array $views = [];

    /** @param list<Style|string> $applied */
    private function __construct(private readonly CodeBlock $block, private readonly array $applied = [])
    {
    }

    public static function of(CodeBlock $block): self
    {
        return new self($block);
    }

    public function apply(Style|string $style): self
    {
        return new self($this->block, [...$this->applied, $style]);
    }

    public function render(): View
    {
        return $this->views[''] ??= $this->dressed($this->block->render());
    }

    public function renderFor(Theme $theme): View
    {
        return $this->views[$theme->codeTheme ?? ''] ??= $this->dressed($this->block->renderFor($theme));
    }

    private function dressed(View $view): View
    {
        foreach ($this->applied as $style) {
            $view = $view->apply($style);
        }
        return $view;
    }
}
