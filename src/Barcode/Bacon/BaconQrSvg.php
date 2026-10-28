<?php

declare(strict_types=1);

namespace Papercut\Barcode\Bacon;

use BaconQrCode\Common\{ErrorCorrectionLevel, Mode, Version};
use BaconQrCode\Encoder\{ByteMatrix, QrCode};
use BaconQrCode\Renderer\Color\{Alpha, ColorInterface, Rgb};
use BaconQrCode\Renderer\Eye\{PointyEye, SimpleCircleEye, SquareEye};
use BaconQrCode\Renderer\Image\{ImageBackEndInterface, ImagickImageBackEnd, SvgImageBackEnd};
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Module\{DotsModule, RoundnessModule, SquareModule};
use BaconQrCode\Renderer\RendererStyle\{EyeFill, Fill, Gradient, GradientType, RendererStyle};
use Papercut\Barcode\{ErrorCorrection, QrDesign, QrEyes, QrGradient, QrShape};
use Papercut\Exception\{InvalidValue, MissingPackage};
use Papercut\Value\Color;

/**
 * Draws a QR code's modules as an SVG with BaconQrCode, an optional package. Papercut encodes
 * the grid; Bacon only draws it, so styled and plain codes carry the same modules.
 */
final class BaconQrSvg
{
    /** @throws MissingPackage when bacon/bacon-qr-code is not installed */
    public static function require(): void
    {
        if (!class_exists(ImageRenderer::class)) {
            throw MissingPackage::for('Styled QR codes', 'bacon/bacon-qr-code', plural: true);
        }
    }

    /**
     * Bacon places the eyes by the grid's size, so it must be a real QR size: 21 modules for
     * version 1, and 4 more for each version up to 177.
     *
     * @throws InvalidValue
     */
    public static function checkSize(int $modules): void
    {
        if ($modules < 21 || $modules > 177 || ($modules - 17) % 4 !== 0) {
            throw InvalidValue::for('QR modules', "$modules across")->because('a styled code needs a standard QR grid: 21 to 177 modules, in steps of 4');
        }
    }

    /**
     * The code in module units, quiet zone included, with no background.
     *
     * @param non-empty-list<non-empty-list<bool>> $modules
     */
    public static function svg(array $modules, int $quietZone, ErrorCorrection $level, QrDesign $design, Color $color): string
    {
        $size = count($modules);

        return self::render($modules, $quietZone, $level, $design, $color, $size + 2 * $quietZone, new SvgImageBackEnd());
    }

    /**
     * The code as a square PNG $pixels across, quiet zone included and see-through, drawn by
     * Imagick: for formats that take pictures rather than vectors.
     *
     * @param non-empty-list<non-empty-list<bool>> $modules
     * @param positive-int $pixels
     * @throws \RuntimeException when the imagick extension is not loaded
     */
    public static function png(array $modules, int $quietZone, ErrorCorrection $level, QrDesign $design, Color $color, int $pixels): string
    {
        if (!extension_loaded('imagick')) {
            throw new \RuntimeException('Styled QR codes as pictures need the Imagick extension (ext-imagick).');
        }

        return self::render($modules, $quietZone, $level, $design, $color, $pixels, new ImagickImageBackEnd('png'));
    }

    /** @param non-empty-list<non-empty-list<bool>> $modules */
    private static function render(array $modules, int $quietZone, ErrorCorrection $level, QrDesign $design, Color $color, int $pixels, ImageBackEndInterface $backend): string
    {
        self::require();
        $size = count($modules);
        self::checkSize($size);
        $matrix = new ByteMatrix($size, $size);
        foreach ($modules as $y => $row) {
            foreach ($row as $x => $dark) {
                $matrix->set($x, $y, $dark ? 1 : 0);
            }
        }
        // Only the matrix is drawn; mode and mask are what the grid already holds, not re-chosen.
        $code = new QrCode(Mode::BYTE(), self::level($level), Version::getVersionForNumber(intdiv($size - 17, 4)), 0, $matrix);

        $style = new RendererStyle($pixels, $quietZone, self::module($design->shape), self::eye($design->eyes), self::fill($design, $color));

        return (new ImageRenderer($style, $backend))->render($code);
    }

    private static function fill(QrDesign $design, Color $color): Fill
    {
        $none = new Alpha(0, new Rgb(255, 255, 255));
        $eye = $design->eyeColor === null ? EyeFill::inherit() : EyeFill::uniform(self::color($design->eyeColor));
        if ($design->gradientFrom !== null && $design->gradientTo !== null) {
            $gradient = new Gradient(self::color($design->gradientFrom), self::color($design->gradientTo), self::gradientType($design->gradientType));

            return Fill::withForegroundGradient($none, $gradient, $eye, $eye, $eye);
        }

        return Fill::withForegroundColor($none, self::color($color), $eye, $eye, $eye);
    }

    private static function color(Color $c): ColorInterface
    {
        $rgb = new Rgb($c->r, $c->g, $c->b);

        return $c->isOpaque() ? $rgb : new Alpha((int) round($c->alpha * 100), $rgb);
    }

    private static function module(QrShape $shape): SquareModule|RoundnessModule|DotsModule
    {
        return match ($shape) {
            QrShape::Square => SquareModule::instance(),
            QrShape::Rounded => new RoundnessModule(RoundnessModule::STRONG),
            QrShape::Dots => new DotsModule(DotsModule::LARGE),
        };
    }

    private static function eye(QrEyes $eyes): SquareEye|SimpleCircleEye|PointyEye
    {
        return match ($eyes) {
            QrEyes::Square => SquareEye::instance(),
            QrEyes::Dot => SimpleCircleEye::instance(),
            QrEyes::Pointy => PointyEye::instance(),
        };
    }

    private static function gradientType(QrGradient $type): GradientType
    {
        return match ($type) {
            QrGradient::Horizontal => GradientType::HORIZONTAL(),
            QrGradient::Vertical => GradientType::VERTICAL(),
            QrGradient::Diagonal => GradientType::DIAGONAL(),
            QrGradient::InverseDiagonal => GradientType::INVERSE_DIAGONAL(),
            QrGradient::Radial => GradientType::RADIAL(),
        };
    }

    /** @internal shared with BaconQrEncoder */
    public static function level(ErrorCorrection $level): ErrorCorrectionLevel
    {
        return match ($level) {
            ErrorCorrection::Low => ErrorCorrectionLevel::L(),
            ErrorCorrection::Medium => ErrorCorrectionLevel::M(),
            ErrorCorrection::Quartile => ErrorCorrectionLevel::Q(),
            ErrorCorrection::High => ErrorCorrectionLevel::H(),
        };
    }
}
