<?php

declare(strict_types=1);

namespace Papercut\Barcode\Bacon;

use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Exception\ExceptionInterface;
use Papercut\Barcode\{ErrorCorrection, QrEncoder};
use Papercut\Exception\{InvalidValue, MissingPackage};

/**
 * BaconQrCode's encoder, from the optional bacon/bacon-qr-code package. It holds every QR size,
 * up to 2,953 bytes at Low error correction, where php-pdf's encoder stops at 213 bytes at Medium.
 * Text is written as UTF-8 bytes without an ECI header, as php-pdf's encoder writes it, since
 * some scanners stumble on the header.
 */
final class BaconQrEncoder implements QrEncoder
{
    /** @throws MissingPackage when bacon/bacon-qr-code is not installed */
    public function __construct()
    {
        if (!class_exists(Encoder::class)) {
            throw MissingPackage::for('BaconQrEncoder', 'bacon/bacon-qr-code');
        }
    }

    public function encode(string $data, ErrorCorrection $level): array
    {
        if ($data === '') {
            throw InvalidValue::for('QR code', "''")->because('there is nothing to encode');
        }
        try {
            $matrix = Encoder::encode($data, BaconQrSvg::level($level), 'UTF-8', prefixEci: false)->getMatrix();
        } catch (ExceptionInterface $e) {
            throw InvalidValue::for('QR code', sprintf('%d bytes', strlen($data)))
                ->because(sprintf('it is too long at %s error correction, or not valid UTF-8 (%s); shorten it or lower the error correction', strtolower($level->name), $e->getMessage()));
        }
        $modules = [];
        for ($y = 0, $n = $matrix->getHeight(); $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                $modules[$y][$x] = $matrix->get($x, $y) === 1;
            }
        }
        /** @var non-empty-list<non-empty-list<bool>> $modules */
        return $modules;
    }
}
