<?php

declare(strict_types=1);

namespace Papercut\Tests\Tree;

use Papercut\{Component, Node, Text};
use Papercut\Tree\Expander;
use PHPUnit\Framework\TestCase;

final class ExpanderTest extends TestCase
{
    public function test_expands_nested_components(): void
    {
        $text = Text::make('x');
        $inner = new class ($text) implements Component {
            public function __construct(private readonly Node $n) {}
            public function render(): Node|Component
            {
                return $this->n;
            }
        };
        $outer = new class ($inner) implements Component {
            public function __construct(private readonly Component $c) {}
            public function render(): Node|Component
            {
                return $this->c;
            }
        };
        self::assertSame($text, Expander::expand($outer));
    }

    public function test_self_rendering_component_throws_after_max_depth(): void
    {
        $loop = new class implements Component {
            public function render(): Node|Component
            {
                return $this;
            }
        };
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('did not render to a node within 100 levels');
        Expander::expand($loop);
    }

    public function test_depth_limit_is_exactly_100_levels(): void
    {
        self::assertInstanceOf(Text::class, Expander::expand(self::nested(100)));
        $this->expectException(\LogicException::class);
        Expander::expand(self::nested(101));
    }

    private static function nested(int $levels): Component
    {
        $c = new class (Text::make('x')) implements Component {
            public function __construct(private readonly Node $n) {}
            public function render(): Node|Component
            {
                return $this->n;
            }
        };
        for ($i = 1; $i < $levels; $i++) {
            $c = new class ($c) implements Component {
                public function __construct(private readonly Component $c) {}
                public function render(): Node|Component
                {
                    return $this->c;
                }
            };
        }
        return $c;
    }
}
