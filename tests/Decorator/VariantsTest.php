<?php

declare(strict_types=1);

namespace Papercut\Tests\Decorator;

use InvalidArgumentException;
use Papercut\{Node, Text, View};
use Papercut\Decorator\{Badge, Blockquote, Callout, Card, ChoiceList, Decorator, Field, KeyValueList, KeyValuePair, Section};
use Papercut\Style\{ComputedStyle, Style, StyleResolver, Theme};
use Papercut\Value\Color;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

final class VariantsTest extends TestCase
{
    private function child(View $view, int ...$path): Node
    {
        $node = $view;
        foreach ($path as $i) {
            self::assertInstanceOf(View::class, $node);
            $node = $node->children()[$i];
            self::assertInstanceOf(Node::class, $node);
        }
        return $node;
    }

    private function computed(Theme $theme, Node $node): ComputedStyle
    {
        return (new StyleResolver($theme))->resolve($node, ComputedStyle::root($theme));
    }

    public function test_variant_reaches_title_only(): void
    {
        $theme = Theme::default()->withStyle('section.title.large', Style::make(fontSize: pt(14)));
        $outer = Section::make('Body')->title('Head')->variant('large')->render();

        self::assertSame(14.0, $this->computed($theme, $this->child($outer, 0, 0))->fontSize);
        self::assertNotSame(14.0, $this->computed($theme, $this->child($outer, 1))->fontSize);
        self::assertNotSame(14.0, $this->computed($theme, $outer)->fontSize);
    }

    public function test_variant_on_outer_box(): void
    {
        $theme = Theme::default()->withStyle('section.large', Style::make(background: Color::hex('#00ff00'), color: Color::hex('#0000ff')));
        $outer = Section::make('Body')->variant('large')->color(Color::hex('#ff0000'))->render();

        self::assertSame('section section.large?', $outer->styleName());
        $computed = $this->computed($theme, $outer);
        self::assertSame(255, $computed->background?->g);
        self::assertSame(255, $computed->color->r);

        $named = Section::make('Body')->variant('large')->apply('mine')->render();
        $theme = $theme->withStyle('mine', Style::make(background: Color::hex('#112233')));
        self::assertSame(0x11, $this->computed($theme, $named)->background?->r);
    }

    public function test_unknown_variant_is_noop_in_php(): void
    {
        $plain = Section::make('Body')->title('Head')->render();
        $varied = Section::make('Body')->title('Head')->variant('huge')->render();
        $theme = Theme::default();

        self::assertEquals(
            $this->computed($theme, $this->child($plain, 0, 0)),
            $this->computed($theme, $this->child($varied, 0, 0)),
        );
        self::assertEquals($this->computed($theme, $plain), $this->computed($theme, $varied));
    }

    public function test_tone_then_variant_order(): void
    {
        $theme = Theme::default()
            ->withStyle('callout.title.warning', Style::make(color: Color::hex('#ff0000')))
            ->withStyle('callout.title.large', Style::make(color: Color::hex('#0000ff')));
        $outer = Callout::make('Body')->tone('warning')->title('T')->variant('large')->render();

        $title = $this->child($outer, 0);
        self::assertInstanceOf(\Papercut\Text::class, $title);
        self::assertSame('callout.title callout.title.warning callout.title.large?', $title->styleName());
        self::assertSame(255, $this->computed($theme, $this->child($outer, 0))->color->b);
        self::assertSame(0, $this->computed($theme, $this->child($outer, 0))->color->r);
    }

    public function test_optional_name_skipped_by_theme(): void
    {
        $theme = Theme::default();
        self::assertEquals($theme->named('section'), $theme->named('section nothing.here?'));
        self::assertEquals(new Style(), $theme->named('nothing.here?'));
        $this->expectException(\Papercut\Exception\UnknownAttribute::class);
        $theme->named('nothing.here');
    }

    public function test_optional_name_found_in_theme_applies(): void
    {
        $theme = Theme::default()->withStyle('x', Style::make(fontSize: pt(33)));
        self::assertSame(33.0, $theme->named('x?')->fontSize?->points);
    }

    public function test_invalid_variant_word_throws(): void
    {
        foreach (['Large', 'a_b', 'big!', '', '  '] as $bad) {
            try {
                Section::make()->variant($bad);
                self::fail("Expected InvalidArgumentException for '$bad'");
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_variants_append_and_are_immutable(): void
    {
        $a = Section::make();
        $b = $a->variant('large compact')->variant('x-1');
        self::assertSame([], $a->variants());
        self::assertSame(['large', 'compact', 'x-1'], $b->variants());
    }

    public function test_with_style_rejects_optional_marker(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Theme::default()->withStyle('x?', Style::make());
    }

    public function test_tone_rejects_optional_marker(): void
    {
        foreach ([fn () => Callout::make()->tone('a?'), fn () => Badge::make('b')->tone('a?')] as $make) {
            try {
                $make();
                self::fail('Expected InvalidArgumentException');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /** @return iterable<string, array{Decorator}> */
    public static function fullDecorators(): iterable
    {
        yield 'badge' => [Badge::make('b')->tone('warning')];
        yield 'blockquote' => [Blockquote::make('q')->cite('c')];
        yield 'blockquote node cite' => [Blockquote::make('q')->cite(Text::make('c'))];
        yield 'card' => [Card::make('x')->title('t')];
        yield 'callout' => [Callout::make('x')->tone('warning')->title('t')];
        yield 'radio' => [ChoiceList::radio()->option('a', true)->option('b')->emphasizeSelected()];
        yield 'checkbox' => [ChoiceList::checkbox()->option('a', true)->option('b')->emphasizeSelected()];
        yield 'field labelled' => [Field::make()->label('l')->placeholder('p')->lines(2)];
        yield 'key-value' => [KeyValueList::make(KeyValuePair::of('k', 'v'))];
        yield 'section' => [Section::make('x')->title('t')->number(1)->rule()];
    }

    #[DataProvider('fullDecorators')]
    public function test_applied_variant_names_stay_within_part_names(Decorator $decorator): void
    {
        $names = [];
        $this->collect($decorator->variant('zz')->render(), $names, []);
        $variantParts = [];
        $base = [];
        foreach (array_keys($names) as $name) {
            if (str_ends_with($name, '.zz?')) {
                $variantParts[] = substr($name, 0, -4);
            } elseif (!str_ends_with($name, '?')) {
                $base[] = $name;
            }
        }
        $parts = $decorator->partNames();
        self::assertSame([], array_values(array_diff($variantParts, $parts)), 'variant names for non-parts');
        self::assertSame([], array_values(array_diff($base, $parts, $this->toneNames($base))), 'built-in names missing from partNames()');
        // every part the decorator applies in this configuration takes a variant
        foreach ($base as $name) {
            if (in_array($name, $parts, true)) {
                self::assertContains($name, $variantParts, "$name has no variant name");
            }
        }
    }

    /**
     * @param list<string> $base
     * @return list<string> names that are a tone suffix of another applied name
     */
    private function toneNames(array $base): array
    {
        return array_values(array_filter($base, static fn (string $n): bool => (bool) preg_match('/\.(warning)$/', $n)));
    }

    /**
     * @param array<string, true> $out
     * @param list<int> $seen
     */
    private function collect(mixed $x, array &$out, array $seen): void
    {
        if (is_array($x)) {
            foreach ($x as $v) {
                $this->collect($v, $out, $seen);
            }
            return;
        }
        if (!is_object($x) || !str_starts_with($x::class, 'Papercut\\') || in_array(spl_object_id($x), $seen, true)) {
            return;
        }
        $seen[] = spl_object_id($x);
        if (method_exists($x, 'styleName') && is_string($x->styleName())) {
            foreach (preg_split('/\s+/', $x->styleName(), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
                $out[$w] = true;
            }
        }
        for ($r = new \ReflectionObject($x); $r !== false; $r = $r->getParentClass()) {
            foreach ($r->getProperties() as $p) {
                if (!$p->isStatic() && $p->isInitialized($x)) {
                    $this->collect($p->getValue($x), $out, $seen);
                }
            }
        }
    }

    /** @return iterable<string, array{Decorator, string}> */
    public static function collidingVariants(): iterable
    {
        yield 'selected on choices' => [ChoiceList::checkbox()->option('a', true), 'selected'];
        yield 'radio on choices' => [ChoiceList::radio()->option('a'), 'radio'];
        yield 'title on a card' => [Card::make('x')->title('t'), 'title'];
    }

    #[DataProvider('collidingVariants')]
    public function test_a_variant_equal_to_a_part_name_segment_is_rejected(Decorator $decorator, string $word): void
    {
        $this->expectException(\Papercut\Exception\InvalidVariant::class);
        $this->expectExceptionMessage("'$word' collides");
        $decorator->variant($word);
    }

    /** @return iterable<string, array{Decorator, string}> */
    public static function toneVariants(): iterable
    {
        yield 'note on a callout' => [Callout::make('x'), 'note'];
        yield 'warning on a callout' => [Callout::make('x')->tone('note'), 'warning'];
        yield 'danger on a badge' => [Badge::make('x'), 'danger'];
        yield 'neutral on a badge' => [Badge::make('x'), 'neutral'];
    }

    #[DataProvider('toneVariants')]
    public function test_a_variant_equal_to_a_built_in_tone_is_rejected(Decorator $decorator, string $word): void
    {
        $this->expectException(\Papercut\Exception\InvalidVariant::class);
        $this->expectExceptionMessage("'$word' is one of this decorator's built-in tones");
        $decorator->variant($word);
    }

    public function test_other_variant_words_are_accepted_on_toned_decorators(): void
    {
        self::assertSame(['large'], Callout::make('x')->variant('large')->variants());
        self::assertSame(['neutral-ish'], Badge::make('x')->variant('neutral-ish')->variants());
    }
}
