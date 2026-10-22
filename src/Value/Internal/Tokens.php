<?php

declare(strict_types=1);

namespace Papercut\Value\Internal;

use Papercut\Exception\InvalidValue;

/** @internal */
final class Tokens
{
    /** @return list<string> */
    public static function split(string $input, string $kind): array
    {
        $tokens = preg_split('/\s+/', trim($input), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens === []) {
            throw InvalidValue::for($kind, $input);
        }
        foreach ($tokens as $i => $tok) {
            $next = $tokens[$i + 1] ?? null;
            if ($next !== null
                && preg_match('/^-?(?:\d+(?:\.\d+)?|\.\d+)$/', $tok)
                && preg_match('/^(pt|px|mm|cm|in|fr|%)$/i', $next)) {
                $joined = $tokens;
                $joined[$i] = $tok . strtolower($next);
                unset($joined[$i + 1]);
                throw InvalidValue::for($kind, $input, implode(' ', $joined));
            }
        }
        return $tokens;
    }
}
