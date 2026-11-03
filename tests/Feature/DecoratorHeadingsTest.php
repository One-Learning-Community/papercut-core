<?php

declare(strict_types=1);

namespace Papercut\Tests\Feature;

use Papercut\{Document, Page, Text, Toc};
use Papercut\Decorator\{Card, Section};
use Papercut\Exception\{InvalidStructure, InvalidValue};
use Papercut\Style\{StyleResolver, Theme};
use Papercut\Toc\{TocEntry, TocIndex};
use PHPUnit\Framework\TestCase;

final class DecoratorHeadingsTest extends TestCase
{
    /** @return list<array{int, string}> */
    private static function entries(Document $doc): array
    {
        $index = TocIndex::collect($doc, new StyleResolver(Theme::default()));
        return array_map(static fn (TocEntry $e): array => [$e->level, $e->text], $index->entries(1, 4));
    }

    public function test_section_and_card_titles_become_entries_at_their_level(): void
    {
        $doc = Document::make(Page::a4()->body(
            Toc::make()->levels(1, 4),
            Section::make('Body')->title('Units')->heading(1),
            Section::make('Body')->title('Numbered')->number(2)->heading(2),
            Section::make('Body')->title(Text::make('Own')->bookmark('Own entry'))->number(3)->heading(2),
            Card::make('Body')->title('Unit card')->heading(3),
            Section::make('Body')->title('Plain'),
            Card::make('Body')->title('Plain card'),
        ));

        self::assertSame([[1, 'Units'], [2, '2 Numbered'], [2, 'Own entry'], [3, 'Unit card']], self::entries($doc));
    }

    public function test_a_heading_title_keeps_its_look(): void
    {
        $html = static fn (?int $level): string => Document::make(Page::a4()->body(
            Section::make('Body')->title('Units')->number(1)->heading($level),
            Card::make('Body')->title('Card')->heading($level),
        ))->toHtml()->bytes();

        $plain = $html(null);
        $heading = $html(2);
        self::assertSame(2, substr_count($heading, '<h2'));
        self::assertSame($plain, preg_replace('~<(/?)h2\b~', '<$1p', $heading));
    }

    public function test_heading_levels_are_one_to_four(): void
    {
        $this->expectException(InvalidValue::class);
        Section::make()->heading(5);
    }

    public function test_card_heading_levels_are_one_to_four(): void
    {
        $this->expectException(InvalidValue::class);
        Card::make()->heading(0);
    }

    public function test_a_title_that_is_not_text_cannot_be_a_heading(): void
    {
        $this->expectException(InvalidStructure::class);
        $this->expectExceptionMessage('A card title can only be a heading when it is text');
        Card::make('Body')->title(\Papercut\View::make(Text::make('Boxed')))->heading(1)->render();
    }

    public function test_as_heading_marks_text_without_a_preset(): void
    {
        $t = Text::make('x')->asHeading(3);
        self::assertSame(3, $t->headingLevel());
        self::assertNull($t->preset());
        self::assertSame(2, Text::h2('x')->headingLevel());
        self::assertSame(1, Text::h2('x')->asHeading(1)->headingLevel());
        self::assertNull(Text::p('x')->headingLevel());
    }
}
