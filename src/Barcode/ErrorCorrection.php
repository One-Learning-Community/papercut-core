<?php

declare(strict_types=1);

namespace Papercut\Barcode;

/** How much of a QR code can be damaged or covered and still scan. Higher levels hold less data. */
enum ErrorCorrection: string
{
    /** About 7% can be lost. */
    case Low = 'L';
    /** About 15%; the usual choice for print. */
    case Medium = 'M';
    /** About 25%. */
    case Quartile = 'Q';
    /** About 30%; use it when a logo sits on top. */
    case High = 'H';
}
