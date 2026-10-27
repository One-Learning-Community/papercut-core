<?php

declare(strict_types=1);

namespace Papercut\Barcode;

/** How a styled QR code draws its data modules. */
enum QrShape: string
{
    /** Square modules, joined into solid areas, as a plain code draws them. */
    case Square = 'square';
    /** Modules joined into soft shapes with rounded corners. */
    case Rounded = 'rounded';
    /** Each module a separate round dot. */
    case Dots = 'dots';
}
