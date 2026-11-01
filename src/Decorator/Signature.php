<?php

declare(strict_types=1);

namespace Papercut\Decorator;

use Papercut\{Component, Node, Text, View};
use Papercut\Concerns\{HasStyle, HasVariants};

/**
 * A line to sign on, with the signer's name, role and date under it. What is passed to make()
 * sits on the line: a signature image, a typed name, or nothing for a blank to sign by hand.
 */
final class Signature implements Decorator
{
    use HasStyle;
    use HasVariants;

    /** @var list<Node|Component> */
    private array $children;
    private Node|Component|string|null $name = null;
    private Node|Component|string|null $role = null;
    private Node|Component|string|null $date = null;

    private function __construct()
    {
    }

    public static function make(Node|Component|string ...$children): self
    {
        $signature = new self();
        $signature->children = View::normalize($children);
        return $signature;
    }

    public function name(string|Node|Component $name): self
    {
        $clone = clone $this;
        $clone->name = self::orNull($name);
        return $clone;
    }

    public function role(string|Node|Component $role): self
    {
        $clone = clone $this;
        $clone->role = self::orNull($role);
        return $clone;
    }

    /** Shown as written, so format it the way the document needs. */
    public function date(string|Node|Component $date): self
    {
        $clone = clone $this;
        $clone->date = self::orNull($date);
        return $clone;
    }

    /** @return list<string> */
    public function partNames(): array
    {
        return ['signature', 'signature.line', 'signature.name', 'signature.role', 'signature.date'];
    }

    public function render(): View
    {
        $parts = [View::make(...$this->children)->apply($this->part('signature.line'))];
        foreach (['name' => $this->name, 'role' => $this->role, 'date' => $this->date] as $key => $caption) {
            if ($caption !== null) {
                $parts[] = is_string($caption)
                    ? Text::make($caption)->apply($this->part('signature.' . $key))
                    : View::make($caption)->apply($this->part('signature.' . $key));
            }
        }

        return View::make(...$parts)
            ->apply(trim($this->part('signature') . ' ' . ($this->styleName() ?? '')))
            ->apply($this->style());
    }

    private static function orNull(string|Node|Component $value): Node|Component|string|null
    {
        return is_string($value) && trim($value) === '' ? null : $value;
    }
}
