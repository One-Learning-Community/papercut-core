<?php

declare(strict_types=1);

namespace Papercut\Tests\Barcode;

use Papercut\Barcode\Bacon\BaconQrSvg;
use Papercut\Barcode\{QrDesign, QrShape};
use Papercut\QrCode;
use Papercut\Value\Color;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/** A styled code as a PNG, for formats that take pictures rather than vectors (Word). */
#[RequiresPhpExtension('imagick')]
#[RequiresPhpExtension('gd')]
final class BaconQrPngTest extends TestCase
{
    public function test_a_styled_code_as_a_square_png_with_a_clear_quiet_zone(): void
    {
        $qr = QrCode::make('https://acme.test/pay/INV-142')->shape(QrShape::Dots);
        $design = $qr->design();
        self::assertNotNull($design);

        $png = BaconQrSvg::png($qr->modules(), $qr->quietZoneModules(), $qr->level(), $design, Color::hex('#1d4ed8'), 300);

        self::assertStringStartsWith("\x89PNG", $png);
        $size = getimagesizefromstring($png);
        self::assertNotFalse($size);
        self::assertSame([300, 300], [$size[0], $size[1]]);
        $im = imagecreatefromstring($png);
        self::assertNotFalse($im);
        // The quiet zone is see-through; the top-left eye's outer ring is drawn in the colour.
        self::assertSame(127, imagecolorsforindex($im, (int) imagecolorat($im, 2, 2))['alpha']);
        $module = 300 / $qr->span();
        $eye = imagecolorsforindex($im, (int) imagecolorat($im, (int) (($qr->quietZoneModules() + 0.5) * $module), (int) (($qr->quietZoneModules() + 3) * $module)));
        self::assertSame([0x1d, 0x4e, 0xd8, 0], [$eye['red'], $eye['green'], $eye['blue'], $eye['alpha']]);
    }
}
