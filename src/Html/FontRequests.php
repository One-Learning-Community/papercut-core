<?php

declare(strict_types=1);

namespace Papercut\Html;

use Papercut\Text\{FontSet, FontStyle};

/** Turns what a document used into one FontRequest per family, with the node that first needed it. */
final class FontRequests
{
    /**
     * @param array<string, array{family: string, variants: list<FontVariant>, path: ?string}> $usage from HtmlWriter::fontUsage()
     * @return list<array{request: FontRequest, path: string}> usage order, then the root family, then the fallbacks; one per family, case-insensitively
     */
    public static function collect(array $usage, string $rootFamily, FontSet $fonts): array
    {
        $names = [];
        foreach ($usage as $entry) {
            $names[strtolower($entry['family'])] ??= $entry['family'];
        }
        foreach ([$rootFamily, ...$fonts->fallbacks] as $family) {
            $names[strtolower($family)] ??= $family;
        }

        $registered = [];
        foreach ($fonts->effectiveFaces() as $face) {
            $registered[strtolower($face->family)][] = new FontVariant($face->weight, $face->style);
        }

        $all = [];
        foreach ($usage as $entry) {
            array_push($all, ...$entry['variants']);
        }
        if ($all === []) {
            $all = [new FontVariant(400, FontStyle::Normal)];
        }

        $requests = [];
        foreach ($names as $key => $family) {
            $used = $usage[$key] ?? null;
            if (isset($registered[$key])) {
                $faces = $registered[$key];
            } else {
                $faces = $used['variants'] ?? $all;
            }
            $requests[] = [
                'request' => new FontRequest($family, $faces, isset($registered[$key]), $fonts),
                'path' => $used['path'] ?? 'document',
            ];
        }

        return $requests;
    }
}
