<?php

declare(strict_types=1);

namespace Papercut\Tests\Node;

use Papercut\Equation;
use Papercut\Math\Math;
use Papercut\Math\Node\Fraction;
use PHPUnit\Framework\TestCase;

final class EquationTest extends TestCase
{
    public function test_named_style_and_debug_name(): void
    {
        $e = Equation::of('x = 1');
        $this->assertSame('equation', $e->styleName());
        $this->assertSame('equation', $e->debugName());
    }

    public function test_a_single_node_is_the_root(): void
    {
        $frac = Math::frac('a', 'b');
        $this->assertSame($frac, Equation::of($frac)->math());
    }

    public function test_several_parts_become_a_row(): void
    {
        $e = Equation::of('x =', Math::frac('a', 'b'));
        $this->assertNotInstanceOf(Fraction::class, $e->math());
    }

    public function test_style_modifiers_are_immutable(): void
    {
        $a = Equation::of('x');
        $b = $a->textAlign('center');
        $this->assertNotSame($a, $b);
        $this->assertSame($a->math(), $b->math());
    }
}
