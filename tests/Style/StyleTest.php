<?php

declare(strict_types=1);

namespace Papercut\Tests\Style;

use Papercut\Exception\{InvalidValue, UnknownAttribute};
use Papercut\Style\{Align, Direction, Justify, Style, TextAlign};
use Papercut\Value\{Auto, Color, Edges, Length, Percent};
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

final class StyleTest extends TestCase
{
    public function test_merge_prefers_non_null_override(): void
    {
        $base = Style::make(padding: Edges::all(pt(4)), color: Color::black(), grow: 1.0);
        $merged = $base->merge(Style::make(color: Color::white()));
        self::assertSame(4.0, $merged->padding?->top->points);
        self::assertSame('#ffffff', (string) $merged->color);
        self::assertSame(1.0, $merged->grow);
        self::assertTrue(Style::make()->isEmpty());
        self::assertFalse($base->isEmpty());
    }

    public function test_from_attributes_parses_strings(): void
    {
        $s = Style::fromAttributes([
            'padding' => '12pt 6pt',
            'width' => '50%',
            'height' => 'auto',
            'color' => 'slate-700',
            'direction' => 'row',
            'justify' => 'space-between',
            'align-items' => 'center',
            'text-align' => 'right',
            'gap' => '8pt',
            'grow' => '1',
            'bold' => '',
            'keep-together' => 'true',
            'italic' => 'false',
            'min-lines' => '3',
            'line-height' => '1.6',
            'font' => 'Inter',
            'font-size' => '12pt',
            'opacity' => '0.5',
        ]);
        self::assertSame(6.0, $s->padding?->right->points);
        self::assertInstanceOf(Percent::class, $s->width);
        self::assertInstanceOf(Auto::class, $s->height);
        self::assertSame('#334155', (string) $s->color);
        self::assertSame(Direction::Row, $s->direction);
        self::assertSame(Justify::SpaceBetween, $s->justify);
        self::assertSame(Align::Center, $s->alignItems);
        self::assertSame(TextAlign::Right, $s->textAlign);
        self::assertSame(1.0, $s->grow);
        self::assertSame(700, $s->fontWeight);
        self::assertTrue($s->keepTogether);
        self::assertFalse($s->italic);
        self::assertSame(3, $s->minLines);
        self::assertSame(1.6, $s->lineHeight);
        self::assertSame(12.0, $s->fontSize?->points);
        self::assertSame(0.5, $s->opacity);
    }

    public function test_typed_values_pass_through(): void
    {
        $len = pt(3);
        self::assertSame($len, Style::fromAttributes(['gap' => $len])->gap);
        self::assertSame(Direction::Column, Style::fromAttributes(['direction' => Direction::Column])->direction);
    }

    public function test_unknown_attribute_suggests(): void
    {
        $this->expectException(UnknownAttribute::class);
        $this->expectExceptionMessage('Unknown attribute "paddng". Did you mean "padding"?');
        Style::fromAttributes(['paddng' => '4pt']);
    }

    public function test_invalid_value_carries_attribute_context(): void
    {
        try {
            Style::fromAttributes(['margin' => '20 mm']);
            self::fail('expected');
        } catch (InvalidValue $e) {
            self::assertSame('Invalid edges "20 mm" for margin. Did you mean "20mm"?', $e->getMessage());
        }
    }

    public function test_invalid_enum_value(): void
    {
        $this->expectException(InvalidValue::class);
        Style::fromAttributes(['justify' => 'middle']);
    }

    public function test_grow_must_be_numeric_non_negative(): void
    {
        $this->expectException(InvalidValue::class);
        Style::fromAttributes(['grow' => '-1']);
    }

    public function test_length_or_auto_values(): void
    {
        self::assertInstanceOf(Length::class, Style::fromAttributes(['basis' => '20mm'])->basis);
    }

    public function test_make_signature_matches_constructor(): void
    {
        $sig = static fn (\ReflectionFunctionAbstract $f): array => array_map(
            static fn (\ReflectionParameter $p): array => [
                $p->getName(),
                (string) $p->getType(),
                $p->isDefaultValueAvailable() ? $p->getDefaultValue() : '__none__',
            ],
            $f->getParameters(),
        );
        self::assertSame(
            $sig(new \ReflectionMethod(Style::class, '__construct')),
            $sig(new \ReflectionMethod(Style::class, 'make')),
        );
    }

    public function test_enum_values_are_trimmed_and_case_insensitive(): void
    {
        self::assertSame(Justify::Center, Style::fromAttributes(['justify' => ' Center'])->justify);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidRanges(): iterable
    {
        yield 'weight zero' => ['weight', '0'];
        yield 'weight too big' => ['weight', '1001'];
        yield 'font-size zero' => ['font-size', '0'];
        yield 'line-height zero' => ['line-height', '0'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidRanges')]
    public function test_value_ranges_are_enforced(string $key, string $value): void
    {
        try {
            Style::fromAttributes([$key => $value]);
            self::fail('expected');
        } catch (InvalidValue $e) {
            self::assertStringContainsString('for ' . $key . '.', $e->getMessage());
        }
    }

    public function test_constructor_rejects_font_weight_out_of_range(): void
    {
        foreach ([0, -100, 1001] as $w) {
            try {
                new Style(fontWeight: $w);
                self::fail("accepted $w");
            } catch (\Papercut\Exception\InvalidValue $e) {
                self::assertSame('font weight', $e->kind);
                self::assertSame((string) $w, $e->input);
            }
        }
        self::assertSame(1, (new Style(fontWeight: 1))->fontWeight);
        self::assertSame(1000, (new Style(fontWeight: 1000))->fontWeight);
    }
}
