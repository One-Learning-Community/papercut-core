<?php

declare(strict_types=1);

namespace Papercut\Backend\PhpPdf;

use Dskripchenko\PhpPdf\Barcode\{QrEccLevel, QrEncoder as PhpPdfEncoder};
use Papercut\Barcode\{ErrorCorrection, QrEncoder};
use Papercut\Exception\InvalidValue;

/** php-pdf's encoder: QR versions 1 to 10, so up to 213 bytes at Medium. */
final class PhpPdfQrEncoder implements QrEncoder
{
    public function encode(string $data, ErrorCorrection $level): array
    {
        if ($data === '') {
            throw InvalidValue::for('QR code', "''")->because('there is nothing to encode');
        }
        try {
            $modules = (new PhpPdfEncoder($data, QrEccLevel::from($level->value)))->modules();
        } catch (\InvalidArgumentException $e) {
            throw InvalidValue::for('QR code', sprintf('%d bytes', strlen($data)))
                ->because(sprintf('it is too long at %s error correction; the most is %d bytes, so shorten it or lower the error correction', strtolower($level->name), PhpPdfEncoder::maxCapacityForLevel($level->value)));
        }
        /** @var non-empty-list<non-empty-list<bool>> $modules */
        return $modules;
    }
}
