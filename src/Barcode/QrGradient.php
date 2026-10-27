<?php

declare(strict_types=1);

namespace Papercut\Barcode;

/** Which way a styled QR code's gradient runs across the code. */
enum QrGradient: string
{
    case Horizontal = 'horizontal';
    case Vertical = 'vertical';
    /** From the top-left corner to the bottom-right one. */
    case Diagonal = 'diagonal';
    /** From the bottom-left corner to the top-right one. */
    case InverseDiagonal = 'inverse-diagonal';
    /** From the centre outwards. */
    case Radial = 'radial';
}
