<?php

declare(strict_types=1);

namespace Papercut\Barcode;

use Papercut\Value\Color;

/**
 * How a styled QR code looks. The modules use the text color unless a gradient is set, and the
 * eyes use the modules' fill unless they have a color of their own.
 */
final readonly class QrDesign
{
    public function __construct(
        public QrShape $shape = QrShape::Square,
        public QrEyes $eyes = QrEyes::Square,
        public ?Color $eyeColor = null,
        public ?Color $gradientFrom = null,
        public ?Color $gradientTo = null,
        public QrGradient $gradientType = QrGradient::Diagonal,
    ) {
        if (($gradientFrom === null) !== ($gradientTo === null)) {
            throw new \InvalidArgumentException('A QR gradient needs both of its colors.');
        }
    }

    public function with(
        ?QrShape $shape = null,
        ?QrEyes $eyes = null,
        ?Color $eyeColor = null,
        ?Color $gradientFrom = null,
        ?Color $gradientTo = null,
        ?QrGradient $gradientType = null,
    ): self {
        return new self(
            $shape ?? $this->shape,
            $eyes ?? $this->eyes,
            $eyeColor ?? $this->eyeColor,
            $gradientFrom ?? $this->gradientFrom,
            $gradientTo ?? $this->gradientTo,
            $gradientType ?? $this->gradientType,
        );
    }
}
