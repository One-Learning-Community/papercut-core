<?php

declare(strict_types=1);

namespace Papercut\Decorator;

use Papercut\{Component, Node, Row, View};
use Papercut\Concerns\{HasStyle, HasVariants};
use Papercut\Style\Justify;
use Papercut\Value\Length;


/**
 * A list of options with drawn radio or checkbox markers.
 *
 * Markers are bordered boxes (circles for radio) with a filled inner mark when selected,
 * not glyphs, so they look the same in PDF and HTML and need no font support. Each option
 * is a row that stays whole across page breaks; the list may split between options.
 * No validation: a radio list records whatever selection it is given.
 */
final class ChoiceList implements Decorator
{
    use HasStyle;
    use HasVariants;

    /** @var list<ChoiceOption> */
    private array $options = [];
    private bool $emphasizeSelected = false;

    private function __construct(private readonly bool $radio)
    {
    }

    public static function radio(): self
    {
        return new self(true);
    }

    public static function checkbox(): self
    {
        return new self(false);
    }

    public function isRadio(): bool
    {
        return $this->radio;
    }

    public function option(string|Node|Component $label, bool $selected = false): self
    {
        return $this->add(ChoiceOption::of($label, $selected));
    }

    public function add(ChoiceOption ...$options): self
    {
        $clone = clone $this;
        array_push($clone->options, ...$options);
        return $clone;
    }

    /** Selected labels use the `choice.label.selected` style. */
    public function emphasizeSelected(bool $on = true): self
    {
        $clone = clone $this;
        $clone->emphasizeSelected = $on;
        return $clone;
    }

    /** @return list<ChoiceOption> */
    public function options(): array
    {
        return $this->options;
    }

    /** @return list<string> */
    public function partNames(): array
    {
        return ['choice', 'choice.option', 'choice.marker', 'choice.marker.radio', 'choice.marker.selected', 'choice.mark', 'choice.mark.radio', 'choice.label.selected'];
    }

    public function render(): View
    {
        $rows = [];
        foreach ($this->options as $option) {
            $rows[] = Row::make($this->marker($option->selected), $this->label($option))
                ->apply($this->part('choice.option'))
                ->keepTogether(true);
        }

        return View::make(...$rows)
            ->apply(trim($this->part('choice') . ' ' . ($this->styleName() ?? '')))
            ->apply($this->style());
    }

    private function marker(bool $selected): View
    {
        $name = $this->part('choice.marker') . ($this->radio ? ' ' . $this->part('choice.marker.radio') : '') . ($selected ? ' ' . $this->part('choice.marker.selected') : '');
        $marker = View::make();
        if ($selected) {
            $marker = View::make(View::make()->apply($this->part('choice.mark') . ($this->radio ? ' ' . $this->part('choice.mark.radio') : '')));
        }
        // A slot one line tall centres the marker on the label's first line at any font size or line height.
        return View::make($marker->apply($name))->height(Length::lh(1))->justify(Justify::Center)->shrink(0);
    }

    private function label(ChoiceOption $option): View
    {
        $label = View::make($option->label)->grow(1)->shrink(1);
        if ($this->emphasizeSelected && $option->selected) {
            $label = $label->apply($this->part('choice.label.selected'));
        }
        return $label;
    }
}
