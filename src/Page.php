<?php

declare(strict_types=1);

namespace Papercut;

use Papercut\Exception\InvalidValue;
use Papercut\Style\Style;
use Papercut\Value\{Edges, Length};

use function Papercut\{mm, pt};

final class Page implements Node
{
    /** Portrait width and height in points. */
    private const SIZES = [
        'a0' => [2383.94, 3370.39],
        'a1' => [1683.78, 2383.94],
        'a2' => [1190.55, 1683.78],
        'a3' => [841.89, 1190.55],
        'a4' => [595.28, 841.89],
        'a5' => [419.53, 595.28],
        'a6' => [297.64, 419.53],
        'b4' => [708.66, 1000.63],
        'b5' => [498.90, 708.66],
        'letter' => [612.0, 792.0],
        'legal' => [612.0, 1008.0],
        'tabloid' => [792.0, 1224.0],
        'executive' => [522.0, 756.0],
    ];

    private Edges $margins;
    private Length $headerGap;
    private Length $footerGap;
    private bool $landscape = false;
    /** @var list<Node|Component> */
    private array $header = [];
    /** @var list<Node|Component> */
    private array $footer = [];
    /** @var list<Node|Component> */
    private array $background = [];
    /** @var list<Node|Component> */
    private array $body = [];

    private function __construct(private float $w, private float $h)
    {
        $this->margins = Edges::all(mm(20));
        $this->headerGap = mm(4);
        $this->footerGap = mm(4);
    }

    private static function preset(string $name): self
    {
        [$w, $h] = self::SIZES[$name];
        return new self($w, $h);
    }

    public static function a3(): self
    {
        return self::preset('a3');
    }

    public static function a4(): self
    {
        return self::preset('a4');
    }

    public static function a5(): self
    {
        return self::preset('a5');
    }

    public static function a6(): self
    {
        return self::preset('a6');
    }

    public static function letter(): self
    {
        return self::preset('letter');
    }

    public static function legal(): self
    {
        return self::preset('legal');
    }

    public static function size(Length $w, Length $h): self
    {
        $w->absolute('page size');
        $h->absolute('page size');
        if (!is_finite($w->points) || !is_finite($h->points) || $w->points <= 0 || $h->points <= 0) {
            throw InvalidValue::for('page size', $w . ' ' . $h)->because('width and height must be positive');
        }
        return new self($w->points, $h->points);
    }

    /** @return list<string> the names Page::named() accepts */
    public static function sizeNames(): array
    {
        return array_keys(self::SIZES);
    }

    public static function named(string $name): self
    {
        $key = strtolower($name);
        if (!isset(self::SIZES[$key])) {
            throw InvalidValue::for('page size', $name)
                ->because(sprintf('expected one of %s, or use Page::size() for any other size', implode(', ', self::sizeNames())));
        }
        return self::preset($key);
    }

    public function landscape(): self
    {
        $clone = clone $this;
        $clone->landscape = true;
        return $clone;
    }

    public function portrait(): self
    {
        $clone = clone $this;
        $clone->landscape = false;
        return $clone;
    }

    public function margin(Length|Edges ...$v): self
    {
        $v = array_values($v);
        if (count($v) === 1 && $v[0] instanceof Edges) {
            $edges = $v[0];
        } else {
            $lengths = [];
            foreach ($v as $item) {
                if (!$item instanceof Length) {
                    throw new \InvalidArgumentException('Pass one Edges or 1-4 Lengths.');
                }
                $lengths[] = $item;
            }
            if ($lengths === [] || count($lengths) > 4) {
                throw new \InvalidArgumentException('Pass one Edges or 1-4 Lengths.');
            }
            $edges = Edges::of(...$lengths);
        }
        $clone = clone $this;
        $clone->margins = $edges->absolute('page margin');
        return $clone;
    }

    public function headerGap(Length $g): self
    {
        $clone = clone $this;
        $clone->headerGap = $g->absolute('header gap');
        return $clone;
    }

    public function footerGap(Length $g): self
    {
        $clone = clone $this;
        $clone->footerGap = $g->absolute('footer gap');
        return $clone;
    }

    public function header(Node|Component|string ...$nodes): self
    {
        $clone = clone $this;
        $clone->header = [...$this->header, ...View::normalize($nodes)];
        return $clone;
    }

    public function footer(Node|Component|string ...$nodes): self
    {
        $clone = clone $this;
        $clone->footer = [...$this->footer, ...View::normalize($nodes)];
        return $clone;
    }

    public function background(Node|Component|string ...$nodes): self
    {
        $clone = clone $this;
        $clone->background = [...$this->background, ...View::normalize($nodes)];
        return $clone;
    }

    public function body(Node|Component|string ...$nodes): self
    {
        $clone = clone $this;
        $clone->body = [...$this->body, ...View::normalize($nodes)];
        return $clone;
    }

    public function width(): float
    {
        return $this->landscape ? $this->h : $this->w;
    }

    public function height(): float
    {
        return $this->landscape ? $this->w : $this->h;
    }

    public function margins(): Edges
    {
        return $this->margins;
    }

    /**
     * Throws when the margins meet or cross, leaving no room for content. Checked at
     * export, not in margin(), because the size and orientation can change afterwards.
     *
     * @param string $name the page's path in messages: "page", or "page[1]" among several
     */
    public function checkMargins(string $name): void
    {
        $m = $this->margins;
        $fail = static fn (string $pair, string $extent) => InvalidValue::for('page margins', $pair)
            ->within(sprintf('on "%s"', $name))
            ->because(sprintf('they leave no room for content on a page %s', $extent));
        if ($m->left->points + $m->right->points >= $this->width()) {
            throw $fail(sprintf('%s left + %s right', $m->left, $m->right), sprintf('%s wide', pt($this->width())));
        }
        if ($m->top->points + $m->bottom->points >= $this->height()) {
            throw $fail(sprintf('%s top + %s bottom', $m->top, $m->bottom), sprintf('%s tall', pt($this->height())));
        }
    }

    public function headerGapLength(): Length
    {
        return $this->headerGap;
    }

    public function footerGapLength(): Length
    {
        return $this->footerGap;
    }

    /** @return list<Node|Component> */
    public function headerNodes(): array
    {
        return $this->header;
    }

    /** @return list<Node|Component> */
    public function footerNodes(): array
    {
        return $this->footer;
    }

    /** @return list<Node|Component> */
    public function backgroundNodes(): array
    {
        return $this->background;
    }

    /** @return list<Node|Component> */
    public function bodyNodes(): array
    {
        return $this->body;
    }

    public function debugName(): string
    {
        return 'page';
    }

    public function style(): Style
    {
        return new Style();
    }
}
