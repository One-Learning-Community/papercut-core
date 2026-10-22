<?php

declare(strict_types=1);

namespace Papercut\Html;

use Papercut\Text\{FontSet, FontStyle};

/** What a document needs from one family; the question a FontSource answers. */
final readonly class FontRequest
{
    /** @var non-empty-list<FontVariant> normal before italic, then weight ascending; deduplicated */
    public array $faces;

    /**
     * @param list<FontVariant> $faces any order; sorted and deduplicated here so every source can rely on the order
     * @param bool $registered whether the family is registered in $fonts
     * @throws \InvalidArgumentException when $faces is empty
     */
    public function __construct(public string $family, array $faces, public bool $registered, public FontSet $fonts)
    {
        $unique = [];
        foreach ($faces as $face) {
            $unique[($face->style === FontStyle::Italic ? 1 : 0) . ':' . str_pad((string) $face->weight, 6, '0', STR_PAD_LEFT)] = $face;
        }
        ksort($unique, SORT_STRING);
        $sorted = array_values($unique);
        if ($sorted === []) {
            throw new \InvalidArgumentException("A font request needs at least one face: '$family'");
        }
        $this->faces = $sorted;
    }
}
