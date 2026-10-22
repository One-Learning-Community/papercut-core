<?php

declare(strict_types=1);

namespace Papercut\Value;

use Papercut\Exception\InvalidValue;
use Papercut\Value\Internal\Tokens;

final readonly class Edges
{
    private function __construct(
        public Length $top,
        public Length $right,
        public Length $bottom,
        public Length $left,
    ) {
    }

    public static function all(Length $l): self
    {
        return new self($l, $l, $l, $l);
    }

    public static function zero(): self
    {
        return self::all(Length::zero());
    }

    public static function of(Length $top, ?Length $right = null, ?Length $bottom = null, ?Length $left = null): self
    {
        $right ??= $top;
        $bottom ??= $top;
        $left ??= $right;
        return new self($top, $right, $bottom, $left);
    }

    /** @param bool $relative accept em and lh, which only style properties resolve */
    public static function parse(string $input, bool $relative = false): self
    {
        $tokens = Tokens::split($input, 'edges');
        if (count($tokens) > 4) {
            throw InvalidValue::for('edges', $input);
        }
        $lengths = [];
        foreach ($tokens as $i => $tok) {
            try {
                $lengths[] = Length::parse($tok, $relative);
            } catch (InvalidValue $e) {
                if ($e->suggestion === null) {
                    $wrapped = InvalidValue::for('edges', $input, null, $e);
                    throw $e->reason !== null ? $wrapped->because($e->reason) : $wrapped;
                }
                $fixed = $tokens;
                $fixed[$i] = $e->suggestion;
                throw InvalidValue::for('edges', $input, implode(' ', $fixed), $e);
            }
        }
        return self::of(...$lengths);
    }

    /** These edges, if every side is absolute; em and lh are rejected outside style properties. */
    public function absolute(string $kind = 'edges'): self
    {
        foreach ([$this->top, $this->right, $this->bottom, $this->left] as $side) {
            $side->absolute($kind);
        }
        return $this;
    }

    public function isRelative(): bool
    {
        return $this->top->isRelative() || $this->right->isRelative() || $this->bottom->isRelative() || $this->left->isRelative();
    }

    /** These edges in points, given the element's font size and line height. */
    public function resolve(float $em, float $lh): self
    {
        if (!$this->isRelative()) {
            return $this;
        }
        return new self($this->top->resolve($em, $lh), $this->right->resolve($em, $lh), $this->bottom->resolve($em, $lh), $this->left->resolve($em, $lh));
    }

    public function horizontal(): float
    {
        return $this->left->points + $this->right->points;
    }

    public function vertical(): float
    {
        return $this->top->points + $this->bottom->points;
    }
}
