<?php

declare(strict_types=1);

namespace Papercut;

use Papercut\Backend\PhpPdf\PhpPdfQrEncoder;
use Papercut\Barcode\Bacon\BaconQrSvg;
use Papercut\Barcode\{ErrorCorrection, QrDesign, QrEncoder, QrEyes, QrGradient, QrShape};
use Papercut\Concerns\HasStyle;
use Papercut\Exception\{InvalidValue, MissingPackage};
use Papercut\Value\Color;

/**
 * A QR code drawn as vector squares, so it stays sharp at any size and needs no image library.
 * It is square: set width (or height) to size it; the default is 25mm. The dark modules use the
 * text color, and the quiet zone around them is part of the size.
 *
 * Setting a shape, eyes, an eye color or a gradient styles the code, which needs the optional
 * bacon/bacon-qr-code package to draw it. The modules stay the ones encoded here.
 */
final class QrCode implements Styled
{
    use HasStyle;

    /** Modules of blank margin a scanner needs around the code. */
    public const int DEFAULT_QUIET_ZONE = 4;

    private ?QrDesign $design = null;

    /**
     * @param non-empty-list<non-empty-list<bool>> $modules
     */
    private function __construct(
        private readonly array $modules,
        private readonly ?string $value,
        private readonly ErrorCorrection $level,
        private readonly ?QrEncoder $encoder,
        private int $quietZone = self::DEFAULT_QUIET_ZONE,
    ) {
    }

    /**
     * @param QrEncoder|null $encoder null uses php-pdf's encoder, which holds up to 213 bytes at Medium
     * @throws InvalidValue when $value is empty or too long
     */
    public static function make(string $value, ErrorCorrection $level = ErrorCorrection::Medium, ?QrEncoder $encoder = null): self
    {
        $encoder ??= new PhpPdfQrEncoder();

        return new self($encoder->encode($value, $level), $value, $level, $encoder);
    }

    /**
     * A grid you encoded yourself, rows top to bottom, truthy for dark modules.
     *
     * @param array<mixed> $modules checked here, since it usually comes from another library
     * @throws InvalidValue when the grid is empty or not square
     */
    public static function fromModules(array $modules): self
    {
        $size = count($modules);
        if ($size === 0) {
            throw InvalidValue::for('QR modules', '[]')->because('the grid is empty');
        }
        if (!array_is_list($modules)) {
            throw InvalidValue::for('QR modules', 'keyed array')->because('rows must be a list, top to bottom');
        }
        foreach ($modules as $i => $row) {
            if (!is_array($row) || count($row) !== $size || !array_is_list($row)) {
                throw InvalidValue::for('QR modules', "row $i")->because("each row needs $size modules, to make a square");
            }
        }
        /** @var non-empty-list<non-empty-list<bool>> $modules */
        return new self(array_map(static fn (array $row): array => array_map(static fn (mixed $m): bool => (bool) $m, $row), $modules), null, ErrorCorrection::Medium, null);
    }

    /**
     * Encodes the same text again at another level. A code with dots or rounded modules stays at
     * Quartile or above.
     */
    public function errorCorrection(ErrorCorrection $level): self
    {
        if ($this->value === null || $this->encoder === null) {
            throw new \LogicException('A QR code made from modules has no text to encode again.');
        }
        if ($this->design !== null && self::needsQuartile($this->design->shape) && self::rank($level) < self::rank(ErrorCorrection::Quartile)) {
            $level = ErrorCorrection::Quartile;
        }
        $clone = new self($this->encoder->encode($this->value, $level), $this->value, $level, $this->encoder, $this->quietZone);
        $clone->style = $this->style;
        $clone->styleName = $this->styleName;
        $clone->design = $this->design;

        return $clone;
    }

    public function quietZone(int $modules): self
    {
        if ($modules < 0) {
            throw InvalidValue::for('quiet zone', (string) $modules)->because('it cannot be negative');
        }
        $clone = clone $this;
        $clone->quietZone = $modules;

        return $clone;
    }

    /**
     * How the data modules are drawn. Dots and rounded modules leave less ink for a scanner to
     * find, so they raise the error correction to Quartile, re-encoding the text, unless it is
     * already High. A grid from fromModules() has no text to re-encode and is drawn as given.
     *
     * @throws MissingPackage when bacon/bacon-qr-code is not installed
     * @throws InvalidValue when the grid is not a standard QR size, or the text is too long for Quartile
     */
    public function shape(QrShape $shape): self
    {
        $styled = $this->designed(static fn (QrDesign $d): QrDesign => $d->with(shape: $shape));
        if (!self::needsQuartile($shape) || $this->value === null || self::rank($this->level) >= self::rank(ErrorCorrection::Quartile)) {
            return $styled;
        }
        try {
            return $styled->errorCorrection(ErrorCorrection::Quartile);
        } catch (InvalidValue $e) {
            throw InvalidValue::for('QR code', sprintf('%d bytes', strlen($this->value)), previous: $e)
                ->because('dots and rounded modules need quartile error correction or higher to scan reliably, and the text is too long for that; shorten it, use square modules, or encode it with BaconQrEncoder, which holds more');
        }
    }

    private static function needsQuartile(QrShape $shape): bool
    {
        return $shape !== QrShape::Square;
    }

    private static function rank(ErrorCorrection $level): int
    {
        return (int) array_search($level, ErrorCorrection::cases(), true);
    }

    /**
     * How the three corner eyes are drawn.
     *
     * @throws MissingPackage when bacon/bacon-qr-code is not installed
     * @throws InvalidValue when the grid is not a standard QR size
     */
    public function eyes(QrEyes $eyes): self
    {
        return $this->designed(static fn (QrDesign $d): QrDesign => $d->with(eyes: $eyes));
    }

    /**
     * A color for the three eyes, different from the modules'.
     *
     * @throws MissingPackage when bacon/bacon-qr-code is not installed
     * @throws InvalidValue when the grid is not a standard QR size
     */
    public function eyeColor(Color|string $color): self
    {
        $color = self::toColor($color);

        return $this->designed(static fn (QrDesign $d): QrDesign => $d->with(eyeColor: $color));
    }

    /**
     * Fills the modules with a gradient instead of the text color. Keep both colors dark against
     * the background, or the code may not scan.
     *
     * @throws MissingPackage when bacon/bacon-qr-code is not installed
     * @throws InvalidValue when the grid is not a standard QR size
     */
    public function gradient(Color|string $from, Color|string $to, QrGradient $type = QrGradient::Diagonal): self
    {
        $from = self::toColor($from);
        $to = self::toColor($to);

        return $this->designed(static fn (QrDesign $d): QrDesign => $d->with(gradientFrom: $from, gradientTo: $to, gradientType: $type));
    }

    /** The code's styling, or null for a plain code drawn as squares. */
    public function design(): ?QrDesign
    {
        return $this->design;
    }

    /** @param \Closure(QrDesign): QrDesign $change */
    private function designed(\Closure $change): self
    {
        BaconQrSvg::require();
        BaconQrSvg::checkSize(count($this->modules));
        $clone = clone $this;
        $clone->design = $change($this->design ?? new QrDesign());

        return $clone;
    }

    /** @return non-empty-list<non-empty-list<bool>> */
    public function modules(): array
    {
        return $this->modules;
    }

    public function value(): ?string
    {
        return $this->value;
    }

    public function level(): ErrorCorrection
    {
        return $this->level;
    }

    public function quietZoneModules(): int
    {
        return $this->quietZone;
    }

    /** Modules across, quiet zone included. */
    public function span(): int
    {
        return count($this->modules) + 2 * $this->quietZone;
    }

    public function debugName(): string
    {
        return 'qr-code';
    }
}
