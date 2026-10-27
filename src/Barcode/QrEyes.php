<?php

declare(strict_types=1);

namespace Papercut\Barcode;

/** How a styled QR code draws its three corner eyes (the finder patterns). */
enum QrEyes: string
{
    case Square = 'square';
    /** A square ring around a round dot. */
    case Dot = 'dot';
    /** A ring rounded on three corners and square on the one facing the middle of the code, around a round centre. */
    case Pointy = 'pointy';
}
