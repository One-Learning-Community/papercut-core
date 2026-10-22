<?php

declare(strict_types=1);

namespace Papercut\Value;

use Papercut\Exception\InvalidValue;
use Papercut\Value\Internal\Tokens;

final readonly class TrackList
{
    /** @param list<Length|Percent|Fr|Auto> $tracks */
    private function __construct(public array $tracks)
    {
    }

    public static function of(Length|Percent|Fr|Auto ...$tracks): self
    {
        if ($tracks === []) {
            throw InvalidValue::for('column list', '');
        }
        foreach ($tracks as $t) {
            if ($t instanceof Length) {
                $t->absolute('column width');
            }
        }
        return new self(array_values($tracks));
    }

    public static function parse(string $input): self
    {
        $tokens = Tokens::split($input, 'column list');
        $tracks = [];
        foreach ($tokens as $i => $tok) {
            try {
                $tracks[] = match (true) {
                    strtolower($tok) === 'auto' => Auto::instance(),
                    str_ends_with(strtolower($tok), 'fr') => Fr::parse($tok),
                    str_ends_with($tok, '%') => Percent::parse($tok),
                    default => Length::parse($tok),
                };
            } catch (InvalidValue $e) {
                if ($e->suggestion === null) {
                    $wrapped = InvalidValue::for('column list', $input, null, $e);
                    throw $e->reason !== null ? $wrapped->because($e->reason) : $wrapped;
                }
                $fixed = $tokens;
                $fixed[$i] = $e->suggestion;
                throw InvalidValue::for('column list', $input, implode(' ', $fixed), $e);
            }
        }
        return new self($tracks);
    }

    public function count(): int
    {
        return count($this->tracks);
    }
}
