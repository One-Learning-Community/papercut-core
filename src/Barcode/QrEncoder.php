<?php

declare(strict_types=1);

namespace Papercut\Barcode;

use Papercut\Exception\InvalidValue;

/** Turns text into a QR code's grid of modules. */
interface QrEncoder
{
    /**
     * @return non-empty-list<non-empty-list<bool>> rows of modules, top to bottom; true is dark
     * @throws InvalidValue when the text is empty or too long for a QR code at that level
     */
    public function encode(string $data, ErrorCorrection $level): array;
}
