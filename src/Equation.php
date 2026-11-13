<?php

declare(strict_types=1);

namespace Papercut;

use Papercut\Concerns\HasStyle;
use Papercut\Math\Math;
use Papercut\Math\Node\Node as MathNode;

/**
 * A displayed equation, set on a line of its own. It takes the text's size and colour and is laid out in
 * display style with the math font; characters in it stay text in the PDF. It never splits across pages
 * and, wider than its box, overflows rather than breaking. Align it with text-align (left by default).
 */
final class Equation implements Styled
{
    use HasStyle;

    private function __construct(private readonly MathNode $math)
    {
    }

    /**
     * A formula from strings and nodes built with {@see Math}. Strings are tokenized as in Math::row(); a single
     * node is the formula itself.
     */
    public static function of(string|MathNode ...$parts): self
    {
        $e = new self(Math::row(...$parts));
        $e->styleName = 'equation';
        return $e;
    }

    public function math(): MathNode
    {
        return $this->math;
    }

    public function debugName(): string
    {
        return 'equation';
    }
}
